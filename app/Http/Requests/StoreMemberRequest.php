<?php

namespace App\Http\Requests;

use App\Models\Member;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
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
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('national_id') && is_string($this->input('national_id'))) {
            $this->merge([
                'national_id_hash' => Member::hashNationalId($this->input('national_id')),
            ]);
        }
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
            'member_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('members', 'member_number')->where('sacco_id', $saccoId),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'national_id' => [
                'required',
                'string',
                'min:5',
                'max:30',
            ],
            'national_id_hash' => [
                'required',
                'string',
                Rule::unique('members', 'national_id_hash')->where('sacco_id', $saccoId),
            ],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'next_of_kin_name' => ['nullable', 'string', 'max:255'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:50'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:100'],
            'join_date' => ['required', 'date'],
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent_data_processing' => ['accepted'],
            'scoring_consent' => ['required', 'boolean', $this->scoringRequiresDataProcessing(...)],
            'next_of_kin_informed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Credit scoring uses the member's data, so agreeing to it is only valid together with data processing.
     */
    private function scoringRequiresDataProcessing(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->boolean('scoring_consent') && ! $this->boolean('consent_data_processing')) {
            $fail('The member cannot agree to credit scoring without first agreeing to the SACCO collecting and using their information.');
        }
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'national_id_hash.unique' => 'A member with this National ID (NIN) is already registered in this SACCO.',
            'member_number.unique' => 'This Member Number is already in use within your SACCO.',
            'consent_data_processing.accepted' => 'The member must agree to the SACCO collecting and using their information before they can be registered.',
            'scoring_consent.required' => 'Record whether the member agrees to credit scoring (either answer is acceptable).',
        ];
    }

    /**
     * Friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'national_id' => 'national ID',
            'national_id_hash' => 'national ID',
            'consent_data_processing' => 'consent to the SACCO using their information',
            'scoring_consent' => 'credit scoring answer',
        ];
    }
}
