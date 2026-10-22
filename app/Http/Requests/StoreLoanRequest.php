<?php

namespace App\Http\Requests;

use App\Models\Member;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLoanRequest extends FormRequest
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
            'principal' => ['required', 'numeric', 'min:10000'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'interest_calculation_type' => ['required', 'string', Rule::in(['flat', 'simple'])],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'applied_at' => ['required', 'date', 'before_or_equal:today'],
            'guarantor_name' => ['nullable', 'string', 'max:255'],
            'guarantor_phone' => ['nullable', 'string', 'max:50'],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
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
            'principal.min' => 'The minimum loan principal is UGX 10,000.',
            'interest_rate.min' => 'The interest rate cannot be negative.',
            'interest_rate.max' => 'The interest rate cannot be more than 100%.',
            'applied_at.before_or_equal' => 'The application date cannot be in the future.',
            'term_months.min' => 'Loan term must be at least 1 month.',
            'term_months.max' => 'Loan term cannot exceed 120 months (10 years).',
        ];
    }

    /**
     * Configure domain validations.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $memberId = $this->input('member_id');
            if (! $memberId || $validator->errors()->has('member_id')) {
                return;
            }

            $member = Member::find($memberId);
            if (! $member) {
                return;
            }

            if (! $member->isActive()) {
                $validator->errors()->add('member_id', "Loan applications can only be submitted for active members (current status: {$member->status}).");
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
            'term_months' => 'term',
        ];
    }
}
