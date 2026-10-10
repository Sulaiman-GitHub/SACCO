<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DisburseLoanRequest extends FormRequest
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
            'disbursement_method' => ['required', 'string', Rule::in(['cash', 'mtn_momo', 'airtel_money', 'bank_transfer', 'other'])],
            'disbursement_reference' => ['nullable', 'string', 'max:100'],
            'disbursement_date' => ['required', 'date', 'before_or_equal:today'],
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
            'disbursement_method.required' => 'Please select the disbursement payment method.',
            'disbursement_date.before_or_equal' => 'Disbursement date cannot be in the future.',
        ];
    }
}
