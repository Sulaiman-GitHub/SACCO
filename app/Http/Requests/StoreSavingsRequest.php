<?php

namespace App\Http\Requests;

use App\Models\Member;
use App\Models\Saving;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSavingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSaccoAdmin() || $user->isLoanOfficer() || $user->isSuperAdmin());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $saccoId = $this->user()->sacco_id;

        return [
            'member_id' => [
                'required',
                'integer',
                Rule::exists('members', 'id')->where('sacco_id', $saccoId),
            ],
            'type' => ['required', 'string', Rule::in(['deposit', 'withdrawal'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'mtn_momo', 'airtel_money', 'bank_transfer', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_id.exists' => 'The selected member was not found in your SACCO.',
            'amount.required' => 'Enter the transaction amount.',
            'amount.numeric' => 'The transaction amount must be a number.',
            'amount.gt' => 'The transaction amount must be more than zero.',
            'transaction_date.before_or_equal' => 'Transaction date cannot be in the future.',
        ];
    }

    /**
     * Configure the validator instance for domain business rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $memberId = $this->input('member_id');
            $type = $this->input('type');
            $amount = (float) $this->input('amount');

            if (! $memberId || $validator->errors()->has('member_id')) {
                return;
            }

            $member = Member::find($memberId);
            if (! $member) {
                return;
            }

            if (! $member->isActive()) {
                $validator->errors()->add('member_id', "Savings transactions can only be recorded for active members (current status: {$member->status}).");
            }

            // Enforce non-negative ledger balance on withdrawals; the minimum does not apply to a withdrawal that clears the account
            if ($type === 'withdrawal') {
                $currentBalance = $member->savings_balance;
                if ($amount > $currentBalance) {
                    $validator->errors()->add('amount', 'Insufficient savings balance. Member has UGX '.number_format($currentBalance, 2).' available for withdrawal.');
                } elseif (! Saving::meetsMinimum($amount, $currentBalance)) {
                    $validator->errors()->add('amount', 'The minimum withdrawal is UGX '.number_format(Saving::MINIMUM_AMOUNT).', unless you are withdrawing the member\'s full balance of UGX '.number_format($currentBalance, 2).'.');
                }
            } elseif ($type === 'deposit' && $amount < Saving::MINIMUM_AMOUNT) {
                $validator->errors()->add('amount', 'The minimum deposit is UGX '.number_format(Saving::MINIMUM_AMOUNT).'.');
            }
        });
    }

    /**
     * Friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'member_id' => 'member',
        ];
    }
}
