<?php

namespace App\Http\Requests;

use App\Models\WithdrawalRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * How staff paid the member by hand. The amount and destination are never taken from the form.
 */
class RecordWithdrawalPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSaccoAdmin() || $user->isLoanOfficer());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payout_method' => ['required', 'string', Rule::in(array_keys(WithdrawalRequest::PAYOUT_METHODS))],
            'payout_reference' => ['nullable', 'string', 'max:100', 'required_unless:payout_method,cash'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payout_method.required' => 'Choose how you paid the member.',
            'payout_reference.required_unless' => 'Enter the transaction reference from the payment you made.',
        ];
    }
}
