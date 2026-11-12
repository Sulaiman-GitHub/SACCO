<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditTrail;
use App\Services\StaffInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Where an invited staff member lands from the emailed link to choose a password.
 */
class StaffInvitationController extends Controller
{
    public function __construct(private StaffInvitations $invitations) {}

    /**
     * Show the choose-a-password page for a valid link.
     */
    public function show(string $token): View|Response
    {
        $invitation = $this->invitations->findUsable($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        return view('auth.invitation', ['token' => $token, 'staffName' => $invitation->user->name]);
    }

    /**
     * Set the password, verify the address, burn the link and sign the person in.
     */
    public function accept(Request $request, string $token, AuditTrail $audit): RedirectResponse|View|Response
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $invitation = $this->invitations->findUsable($token);

        if ($invitation === null) {
            return $this->invalid();
        }

        $user = $this->invitations->accept($invitation, $validated['password']);

        if ($user === null) {
            return $this->invalid();
        }

        Auth::login($user);
        $request->session()->regenerate();

        $audit->record('staff.invitation_accepted', $user, ['email' => $user->email]);

        return redirect()->route('tenant.dashboard')
            ->with('success', 'Your password is set. Welcome!');
    }

    /**
     * One plain answer for any link that is unknown, used, expired or no longer allowed.
     */
    private function invalid(): Response
    {
        return response()->view('auth.invitation-invalid', [], 410);
    }
}
