<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRepaymentRequest extends FormRequest
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
            'loan_id' => [
                'required',
                'integer',
                Rule::exists('loans', 'id')->where('sacco_id', $saccoId),
            ],
            'amount' => ['required', 'numeric', 'min:1000'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'mtn_momo', 'airtel_money', 'bank_transfer', 'other'])],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'repayment_date' => ['required', 'date', 'before_or_equal:today'],
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
            'loan_id.exists' => 'The selected loan was not found in your SACCO.',
            'amount.min' => 'The minimum repayment installment is UGX 1,000.',
            'repayment_date.before_or_equal' => 'Repayment date cannot be in the future.',
        ];
    }

    /**
     * Configure domain validations.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $loanId = $this->input('loan_id');
            $amount = (float) $this->input('amount');

            if (! $loanId || $validator->errors()->has('loan_id')) {
                return;
            }

            $loan = Loan::find($loanId);
            if (! $loan) {
                return;
            }

            if (! $loan->canAcceptRepayment()) {
                $validator->errors()->add('loan_id', "This loan (status: {$loan->status}) is not active or has already been fully repaid.");

                return;
            }

            $remaining = $loan->remaining_balance;
            if ($amount > ($remaining + 0.01)) {
                $validator->errors()->add('amount', 'Repayment amount (UGX '.number_format($amount, 2).') cannot exceed the remaining loan balance of UGX '.number_format($remaining, 2).'.');
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
            'loan_id' => 'loan',
        ];
    }
}
