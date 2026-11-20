<?php

namespace App\Http\Requests;

use App\Models\Loan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A loan request made by a member from the portal. The rate, interest method, dates and status are
 * never read from the request: the controller sets them from the SACCO's lending policy.
 */
class StoreMemberLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('member') !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'principal' => ['required', 'numeric', 'min:10000', 'max:1000000000'],
            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'guarantor_name' => ['nullable', 'string', 'max:255'],
            'guarantor_phone' => ['nullable', 'string', 'max:50'],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'principal.min' => 'The minimum loan amount is UGX 10,000.',
            'term_months.min' => 'The loan period must be at least 1 month.',
            'term_months.max' => 'The loan period cannot be more than 120 months (10 years).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'principal' => 'amount',
            'term_months' => 'loan period',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $member = $this->user('member');

            if (! $member->isActive()) {
                $validator->errors()->add('principal', 'Your membership is not active, so you cannot request a loan. Please contact your SACCO.');

                return;
            }

            $hasPending = Loan::withoutTenantScope()
                ->where('sacco_id', $member->sacco_id)
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPending) {
                $validator->errors()->add('principal', 'You already have a loan request waiting for the SACCO. You can send another once it has been dealt with.');
            }
        });
    }
}
