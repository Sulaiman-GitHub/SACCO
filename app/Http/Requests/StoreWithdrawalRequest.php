<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A member asking to withdraw savings. Only the amount, the early choice, an optional note and the
 * member's own password come from the browser. The member, SACCO, destination phone, fee and dates
 * are all worked out on the server.
 */
class StoreWithdrawalRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,2'],
            'early' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount you want to withdraw.',
            'amount.numeric' => 'The amount must be a number.',
            'amount.gt' => 'The amount must be more than zero.',
            'amount.decimal' => 'The amount can have at most two decimal places.',
            'password.required' => 'Enter your password to confirm this request.',
        ];
    }
}
