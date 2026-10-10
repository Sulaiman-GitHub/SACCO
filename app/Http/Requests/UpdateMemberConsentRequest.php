<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberConsentRequest extends FormRequest
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
        return [
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
            'consent_data_processing.accepted' => 'The member must agree to the SACCO collecting and using their information.',
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
            'consent_data_processing' => 'consent to the SACCO using their information',
            'scoring_consent' => 'credit scoring answer',
        ];
    }
}
