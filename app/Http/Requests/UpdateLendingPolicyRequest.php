<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLendingPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSaccoAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'approve_below_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'reject_at_or_above_score' => ['required', 'numeric', 'min:0', 'max:100', 'gt:approve_below_score'],
            'max_loan_to_savings_ratio' => ['nullable', 'numeric', 'min:0.1', 'max:999'],
            'min_tenure_months' => ['required', 'integer', 'min:0', 'max:120'],
            'reject_on_past_default' => ['boolean'],
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
            'reject_at_or_above_score.gt' => 'The rejection threshold must be higher than the approval limit.',
        ];
    }
}
