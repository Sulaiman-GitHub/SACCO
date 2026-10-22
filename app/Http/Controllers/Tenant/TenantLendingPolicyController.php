<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLendingPolicyRequest;
use App\Models\LendingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantLendingPolicyController extends Controller
{
    /**
     * Show the SACCO's lending policy thresholds.
     */
    public function edit(): View
    {
        $policy = LendingPolicy::resolveFor((int) Auth::user()->sacco_id);

        return view('tenant.settings.lending_policy', compact('policy'));
    }

    /**
     * Update the SACCO's lending policy thresholds.
     */
    public function update(UpdateLendingPolicyRequest $request): RedirectResponse
    {
        $policy = LendingPolicy::resolveFor((int) Auth::user()->sacco_id);

        $policy->update([
            'approve_below_score' => $request->validated('approve_below_score'),
            'reject_at_or_above_score' => $request->validated('reject_at_or_above_score'),
            'max_loan_to_savings_ratio' => $request->validated('max_loan_to_savings_ratio'),
            'min_tenure_months' => $request->validated('min_tenure_months'),
            'reject_on_past_default' => $request->boolean('reject_on_past_default'),
        ]);

        return redirect()->route('tenant.lending_policy.edit')
            ->with('success', 'Lending policy updated. It applies to new loan applications; existing decisions are unchanged.');
    }
}
