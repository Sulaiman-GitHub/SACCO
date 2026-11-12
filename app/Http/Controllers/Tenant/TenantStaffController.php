<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\StaffInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantStaffController extends Controller
{
    private const MAX_RESENDS_PER_HOUR = 3;

    private const MAIL_FAILED_MESSAGE = 'Account created but the email could not be sent. Use Resend or set a temporary password.';

    /**
     * List all staff members belonging to the authenticated user's SACCO.
     */
    public function index(): View
    {
        // Automatically scoped to logged-in user's sacco_id via TenantScope
        $staff = User::with('pendingInvitation')->where('role', '!=', 'super_admin')->latest()->paginate(15);

        return view('tenant.staff.index', compact('staff'));
    }

    /**
     * Show form to add a staff member within current SACCO.
     */
    public function create(): View
    {
        return view('tenant.staff.create');
    }

    /**
     * Store new staff member in current SACCO.
     *
     * Without a password the member is emailed an invitation to choose their own. A password typed here is the
     * fallback for when email is unavailable: it is set as a temporary password and nothing is emailed.
     */
    public function store(Request $request, StaffInvitations $invitations, AuditTrail $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['loan_officer', 'sacco_admin'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $usesTemporaryPassword = filled($validated['password'] ?? null);
        $token = null;

        // BelongsToTenant trait automatically attaches Auth::user()->sacco_id
        $user = DB::transaction(function () use ($validated, $usesTemporaryPassword, $invitations, $audit, &$token) {
            $user = User::create([
                'sacco_id' => Auth::user()->sacco_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'phone' => $validated['phone'] ?? null,
                // An invited member gets a random password nobody knows until they set their own.
                'password' => Hash::make($usesTemporaryPassword ? $validated['password'] : Str::random(64)),
                'status' => 'active',
            ]);

            if (! $usesTemporaryPassword) {
                $token = $invitations->issue($user, Auth::user());
                $audit->record('staff.invited', $user, $this->auditDetails($user));
            }

            return $user;
        });

        if ($usesTemporaryPassword) {
            return redirect()->route('tenant.staff.index')
                ->with('success', "Staff member '{$user->name}' created with a temporary password.");
        }

        if (! $invitations->deliver($user, $token)) {
            return redirect()->route('tenant.staff.index')->with('error', self::MAIL_FAILED_MESSAGE);
        }

        return redirect()->route('tenant.staff.index')
            ->with('success', "Staff member '{$user->name}' created. An invitation was emailed to {$user->email}.");
    }

    /**
     * Send a staff member a new invitation link, which cancels any earlier one.
     */
    public function resendInvitation(User $user, StaffInvitations $invitations, AuditTrail $audit): RedirectResponse
    {
        $this->ensureOwnSacco($user);

        if (! $user->canUseStaffEmailFlows()) {
            return back()->with('error', 'Activate this staff member before sending an invitation.');
        }

        if ($user->invitationState() === 'active') {
            return back()->with('error', "{$user->name} has already set up their account.");
        }

        $key = 'staff-invitation-resend:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_RESENDS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

            return back()->with('error', "An invitation was already sent to {$user->name} several times. Try again in {$minutes} minute(s).");
        }

        RateLimiter::hit($key, 3600);

        $token = DB::transaction(function () use ($user, $invitations, $audit) {
            $token = $invitations->issue($user, Auth::user());
            $audit->record('staff.invitation_resent', $user, $this->auditDetails($user));

            return $token;
        });

        if (! $invitations->deliver($user, $token)) {
            return back()->with('error', 'The invitation was renewed but the email could not be sent. Use Resend again or set a temporary password.');
        }

        return back()->with('success', "A new invitation was emailed to {$user->email}.");
    }

    /**
     * Show the fallback form for setting a temporary password by hand.
     */
    public function editTemporaryPassword(User $user): View|RedirectResponse
    {
        $this->ensureOwnSacco($user);

        if ($user->id === Auth::id()) {
            return redirect()->route('tenant.staff.index')->with('error', 'You cannot set a temporary password for your own account.');
        }

        return view('tenant.staff.temporary_password', ['staffMember' => $user]);
    }

    /**
     * Set a temporary password by hand, for when email is not available. Any pending invitation is cancelled.
     */
    public function updateTemporaryPassword(Request $request, User $user, StaffInvitations $invitations, AuditTrail $audit): RedirectResponse
    {
        $this->ensureOwnSacco($user);

        if ($user->id === Auth::id()) {
            return redirect()->route('tenant.staff.index')->with('error', 'You cannot set a temporary password for your own account.');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        DB::transaction(function () use ($user, $validated, $invitations, $audit) {
            $user->forceFill(['password' => $validated['password']])->save();
            $invitations->discardPending($user);
            $audit->record('staff.temporary_password_set', $user, $this->auditDetails($user));
        });

        return redirect()->route('tenant.staff.index')
            ->with('success', "A temporary password was set for '{$user->name}'. Share it with them in person.");
    }

    /**
     * Toggle active/inactive status for a staff member.
     */
    public function toggleStatus(User $user): RedirectResponse
    {
        // Ensure user belongs to the current SACCO (TenantScope will already ensure this, but double check)
        if ($user->sacco_id !== Auth::user()->sacco_id) {
            abort(403, 'You can only manage staff of your own SACCO.');
        }

        // Prevent self-deactivation
        if ($user->id === Auth::id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return back()->with('success', "Staff member '{$user->name}' status changed to {$newStatus}.");
    }

    /**
     * Staff of other SACCOs are off limits. The tenant scope already hides them; this is a second check.
     */
    private function ensureOwnSacco(User $user): void
    {
        if ($user->sacco_id !== Auth::user()->sacco_id || $user->isSuperAdmin()) {
            abort(403, 'You can only manage staff of your own SACCO.');
        }
    }

    /**
     * What an invitation audit entry records: who and which role, never a token or password.
     *
     * @return array<string, string>
     */
    private function auditDetails(User $user): array
    {
        return [
            'email' => $user->email,
            'role' => $user->isSaccoAdmin() ? 'SACCO administrator' : 'Loan officer',
        ];
    }
}
