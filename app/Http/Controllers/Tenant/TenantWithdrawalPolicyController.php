<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateWithdrawalPolicyRequest;
use App\Models\WithdrawalPolicy;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantWithdrawalPolicyController extends Controller
{
    private const FIELDS = [
        'notice_days',
        'loan_cover_mode',
        'early_withdrawal_fee_percent',
        'daily_limit_per_member',
        'admin_approval_threshold',
        'security_hold_days',
        'request_expiry_days',
        'payer_must_differ_from_approver',
    ];

    /**
     * Show the SACCO's withdrawal rules (the defaults until the first save).
     */
    public function edit(): View
    {
        $policy = WithdrawalPolicy::resolveFor((int) Auth::user()->sacco_id);

        return view('tenant.settings.withdrawal_policy', compact('policy'));
    }

    /**
     * Save the withdrawal rules and record exactly what changed.
     */
    public function update(UpdateWithdrawalPolicyRequest $request): RedirectResponse
    {
        $policy = WithdrawalPolicy::resolveFor((int) Auth::user()->sacco_id);
        $before = $policy->only(self::FIELDS);

        DB::transaction(function () use ($policy, $request, $before) {
            $policy->fill($request->safe()->only(self::FIELDS))->save();

            $changes = [];
            foreach (self::FIELDS as $field) {
                $new = $policy->getAttribute($field);
                if ($before[$field] !== $new) {
                    $changes[$field] = [$before[$field], $new];
                }
            }

            if ($changes !== []) {
                app(AuditTrail::class)->record('withdrawal_policy.updated', $policy, ['changes' => $changes]);
            }
        });

        return redirect()->route('tenant.withdrawal_policy.edit')
            ->with('success', 'Withdrawal rules updated. They apply to new requests and to the next payout of requests already open.');
    }
}
