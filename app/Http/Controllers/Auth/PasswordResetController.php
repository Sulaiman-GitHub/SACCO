<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\StaffInvitations;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * "Forgot password" for staff, by email. Answers the same way whether or not the address has an account.
 */
class PasswordResetController extends Controller
{
    private const MAX_REQUESTS_PER_HOUR = 5;

    private const NEUTRAL_MESSAGE = 'If that email address belongs to an active staff account, a password reset link has been sent. The link works for 60 minutes.';

    private const BAD_LINK_MESSAGE = 'This password reset link is invalid or has expired. Request a new one.';

    public function showRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Email a reset link to an eligible account, and tell everyone the same thing.
     */
    public function sendLink(Request $request, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = Str::lower($validated['email']);
        $throttleKey = 'password-reset:'.$email.'|'.$request->ip();

        // Counted before looking the account up, so the limit cannot reveal whether it exists.
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_REQUESTS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return back()->withInput($request->only('email'))->withErrors([
                'email' => "Too many reset requests. Try again in {$minutes} minute(s).",
            ]);
        }

        RateLimiter::hit($throttleKey, 3600);

        $user = $this->eligibleUser($email);

        if ($user !== null) {
            try {
                $status = Password::broker()->sendResetLink(['email' => $user->email]);

                if ($status === Password::RESET_LINK_SENT) {
                    $audit->record('staff.password_reset_requested', $user, ['email' => $user->email]);
                }
            } catch (Throwable $e) {
                // Never tell the visitor: the answer must not depend on the account. Never log the token.
                Log::error('Could not start a staff password reset.', ['user_id' => $user->id, 'error' => $e::class]);
            }
        }

        return back()->with('status', self::NEUTRAL_MESSAGE);
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * Set a new password with a valid, unexpired, unused reset link.
     */
    public function reset(Request $request, StaffInvitations $invitations, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $user = $this->eligibleUser(Str::lower($validated['email']));

        if ($user === null) {
            return back()->withInput($request->only('email'))->withErrors(['email' => self::BAD_LINK_MESSAGE]);
        }

        $status = Password::broker()->reset(
            ['email' => $user->email, 'token' => $validated['token'], 'password' => $validated['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) use ($invitations, $audit) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    // Following an emailed link proves the address belongs to them.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                // Any invitation still waiting is now pointless and must not work later.
                $invitations->discardPending($user);

                event(new PasswordReset($user));
                $audit->record('staff.password_reset_completed', $user, ['email' => $user->email]);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))->withErrors(['email' => self::BAD_LINK_MESSAGE]);
        }

        return redirect()->route('login')->with('success', 'Your password has been changed. You can sign in now.');
    }

    /**
     * The account for an email, but only if it is an active staff member of an active SACCO.
     */
    private function eligibleUser(string $email): ?User
    {
        $user = User::withoutTenantScope()->where('email', $email)->first();

        return $user !== null && $user->canUseStaffEmailFlows() ? $user : null;
    }
}
