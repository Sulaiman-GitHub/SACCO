<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ConsentChangeRequest;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The read-only member portal (Module 10). Every query starts from the signed-in member, so a
 * member can only ever see their own records. The only writes are a consent change request and
 * the member's own password (see PortalAuthController).
 */
class PortalController extends Controller
{
    /**
     * Overview: the member's own details, savings balance and loan position.
     */
    public function dashboard(Request $request): View
    {
        $member = $this->member($request);

        return view('portal.dashboard', [
            'member' => $member->load('sacco'),
            'recentSavings' => $member->savings()->latest('transaction_date')->latest('id')->take(5)->get(),
            'loanCount' => $member->loans()->count(),
            'activeLoanCount' => $member->activeLoans()->count(),
            'pendingRequestCount' => $member->consentChangeRequests()->where('status', 'pending')->count(),
        ]);
    }

    /**
     * Full savings history with the computed balance.
     */
    public function savings(Request $request): View
    {
        $member = $this->member($request);

        return view('portal.savings', [
            'member' => $member,
            'transactions' => $member->savings()->latest('transaction_date')->latest('id')->paginate(15),
        ]);
    }

    /**
     * Loans with their status, balance and repayment history. The risk score and the rule-engine
     * decision are deliberately left out.
     */
    public function loans(Request $request): View
    {
        $member = $this->member($request);

        return view('portal.loans', [
            'member' => $member,
            'loans' => $member->loans()->with('repayments')->get(),
        ]);
    }

    /**
     * Current consent status and the member's change requests.
     */
    public function consent(Request $request): View
    {
        $member = $this->member($request);
        $requests = $member->consentChangeRequests()->take(20)->get();
        $pendingTypes = $requests->where('status', 'pending')->pluck('request_type')->all();

        return view('portal.consent', [
            'member' => $member,
            'requests' => $requests,
            'requestableTypes' => array_values(array_diff($member->availableConsentRequestTypes(), $pendingTypes)),
        ]);
    }

    /**
     * Record a request to change consent. Nothing changes until staff act on it.
     */
    public function storeConsentRequest(Request $request): RedirectResponse
    {
        $member = $this->member($request);

        $pendingTypes = $member->consentChangeRequests()->where('status', 'pending')->pluck('request_type')->all();
        $allowedTypes = array_values(array_diff($member->availableConsentRequestTypes(), $pendingTypes));

        $validated = $request->validate([
            'request_type' => ['required', Rule::in($allowedTypes)],
            'member_note' => ['nullable', 'string', 'max:500'],
        ], [
            'request_type.in' => 'That request is not available, or you already have one waiting for the SACCO.',
        ]);

        ConsentChangeRequest::openFor($member, $validated['request_type'], $validated['member_note'] ?? null);

        return redirect()->route('portal.consent')
            ->with('success', 'Your request has been sent to the SACCO. Nothing changes until a staff member has dealt with it.');
    }

    /**
     * The signed-in member.
     */
    private function member(Request $request): Member
    {
        return $request->user('member');
    }
}
