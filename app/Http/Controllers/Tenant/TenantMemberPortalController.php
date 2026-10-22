<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ConsentChangeRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Staff-side management of a member's portal access and of the consent requests members send from it.
 */
class TenantMemberPortalController extends Controller
{
    /**
     * Enable portal access for a member, or reset their password. The temporary password is shown to
     * the admin once, in the next page load, and is never stored in readable form.
     */
    public function issueCredentials(Member $member): RedirectResponse
    {
        $this->ensureTenantOwnership($member);
        abort_unless(Auth::user()->isSaccoAdmin() || Auth::user()->isSuperAdmin(), 403);

        if (! $member->isActive()) {
            return back()->with('error', 'Portal access can only be given to active members.');
        }

        $temporaryPassword = $member->issuePortalCredentials();

        return back()
            ->with('success', "Portal access is on for '{$member->full_name}'. Give them the login details below; the password is shown only now.")
            ->with('portal_credentials', [
                'username' => $member->portal_username,
                'password' => $temporaryPassword,
            ]);
    }

    /**
     * Switch a member's portal access off.
     */
    public function disable(Member $member): RedirectResponse
    {
        $this->ensureTenantOwnership($member);
        abort_unless(Auth::user()->isSaccoAdmin() || Auth::user()->isSuperAdmin(), 403);

        $member->disablePortal();

        return back()->with('success', "Portal access switched off for '{$member->full_name}'.");
    }

    /**
     * Close a consent change request as processed or dismissed. Staff make the actual consent change
     * with the ordinary consent form; this only records that the request was dealt with.
     */
    public function resolveRequest(Request $request, Member $member, ConsentChangeRequest $consentRequest): RedirectResponse
    {
        $this->ensureTenantOwnership($member);
        abort_unless((int) $consentRequest->member_id === (int) $member->id, 404);

        $validated = $request->validate([
            'outcome' => ['required', 'in:processed,dismissed'],
            'handler_note' => [$request->input('outcome') === 'dismissed' ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'handler_note.required' => 'Give the reason for dismissing the request, so the member can be told.',
        ]);

        try {
            $consentRequest->resolve(Auth::user(), $validated['outcome'], $validated['handler_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The consent request has been marked as '.$validated['outcome'].'.');
    }

    /**
     * Ensure the member belongs to the authenticated user's SACCO.
     */
    private function ensureTenantOwnership(Member $member): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $member->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Member record not found in your SACCO.');
        }
    }
}
