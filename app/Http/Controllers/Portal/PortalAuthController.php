<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Show the member portal login form.
     */
    public function showLoginForm(): View
    {
        return view('portal.auth.login');
    }

    /**
     * Handle a member sign-in. Failures share one message so the form never reveals which login
     * names exist, and repeated failures are throttled per login name and address.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'portal_username' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($credentials['portal_username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return back()->onlyInput('portal_username')->withErrors([
                'portal_username' => "Too many sign-in attempts. Try again in {$minutes} minute(s).",
            ]);
        }

        $guard = Auth::guard('member');

        if (! $guard->attempt($credentials + ['portal_enabled' => true])) {
            RateLimiter::hit($throttleKey);

            return $this->failedLogin();
        }

        /** @var Member $member */
        $member = $guard->user();

        if (! $member->canUsePortal()) {
            $guard->logout();
            RateLimiter::hit($throttleKey);

            return $this->failedLogin();
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $member->forceFill(['portal_last_login_at' => Carbon::now()])->saveQuietly();

        return redirect()->intended(route('portal.dashboard'));
    }

    /**
     * Sign the member out of the portal only, leaving any staff session in the same browser alone.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login')->with('success', 'You have been signed out.');
    }

    /**
     * Show the form where a member chooses their own password.
     */
    public function editPassword(Request $request): View
    {
        return view('portal.auth.password', ['member' => $request->user('member')]);
    }

    /**
     * Save the member's new password, which also ends the first-login requirement.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var Member $member */
        $member = $request->user('member');

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($validated['current_password'], $member->getAuthPassword())) {
            return back()->withErrors(['current_password' => 'The current password is not correct.']);
        }

        $member->changePortalPassword($validated['password']);

        return redirect()->route('portal.dashboard')->with('success', 'Your password has been changed.');
    }

    /**
     * Redirect back to the form with the shared failure message.
     */
    private function failedLogin(): RedirectResponse
    {
        return back()->onlyInput('portal_username')->withErrors([
            'portal_username' => 'The login name or password is not correct, or portal access is not available. Please contact your SACCO.',
        ]);
    }
}
