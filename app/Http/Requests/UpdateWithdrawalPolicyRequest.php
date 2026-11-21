<?php

namespace App\Http\Requests;

use App\Models\WithdrawalPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWithdrawalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSaccoAdmin();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notice_days' => ['required', 'integer', 'min:0', 'max:90'],
            'loan_cover_mode' => ['required', 'string', Rule::in(array_keys(WithdrawalPolicy::COVER_MODE_LABELS))],
            'early_withdrawal_fee_percent' => ['required', 'numeric', 'min:0', 'max:20'],
            'daily_limit_per_member' => ['nullable', 'numeric', 'min:1000', 'max:1000000000'],
            'admin_approval_threshold' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'security_hold_days' => ['required', 'integer', 'min:0', 'max:30'],
            'request_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'payer_must_differ_from_approver' => ['required', 'string', Rule::in(array_keys(WithdrawalPolicy::PAYER_RULE_LABELS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'early_withdrawal_fee_percent.max' => 'The early withdrawal fee cannot be more than 20%.',
            'daily_limit_per_member.min' => 'The daily limit must be at least UGX 1,000, or left blank for no limit.',
        ];
    }
}
