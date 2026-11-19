<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberLoanRequest;
use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Services\LoanApplicationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Lets a member ask for a loan. Pricing comes from the SACCO's lending policy, the request is
 * saved as pending, and an officer decides it. The member never sees a score or recommendation.
 */
class PortalLoanRequestController extends Controller
{
    public function create(Request $request): View
    {
        $member = $request->user('member');

        $hasPending = Loan::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->exists();

        return view('portal.loan_request', [
            'member' => $member,
            'hasPending' => $hasPending,
            'policy' => LendingPolicy::resolveFor((int) $member->sacco_id),
        ]);
    }

    public function store(StoreMemberLoanRequest $request, LoanApplicationService $applications): RedirectResponse
    {
        $member = $request->user('member');
        $policy = LendingPolicy::resolveFor((int) $member->sacco_id);
        $validated = $request->validated();

        $loan = $applications->create($member, [
            'principal' => $validated['principal'],
            'term_months' => $validated['term_months'],
            'purpose' => $validated['purpose'] ?? null,
            'guarantor_name' => $validated['guarantor_name'] ?? null,
            'guarantor_phone' => $validated['guarantor_phone'] ?? null,
            'guarantor_relationship' => $validated['guarantor_relationship'] ?? null,
            // Always from the SACCO's policy and the server clock, never from the request
            'interest_rate' => $policy->default_interest_rate,
            'interest_calculation_type' => $policy->default_interest_calculation_type,
            'applied_at' => Carbon::today()->toDateString(),
        ], null, Loan::REQUESTED_VIA_PORTAL);

        return redirect()->route('portal.loans')
            ->with('success', "Your loan request {$loan->loan_reference} has been sent to the SACCO. An officer will review it and you can follow its progress here.");
    }
}
