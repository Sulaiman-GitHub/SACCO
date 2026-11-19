<?php

namespace App\Http\Requests;

use App\Models\Saving;
use App\Support\UgandaPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A member's request to pay by mobile money or card. Only the amount, network and phone come from
 * the browser; the member, SACCO and (for repayments) the loan are decided by the server.
 */
class StorePaymentRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:'.Saving::MINIMUM_AMOUNT, 'max:10000000'],
            'network' => ['required', Rule::in(['mtn', 'airtel', 'card'])],
            'phone' => ['nullable', 'string', 'max:30', 'required_unless:network,card'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.min' => 'The minimum amount is UGX '.number_format(Saving::MINIMUM_AMOUNT).'.',
            'amount.max' => 'The largest single online payment is UGX 10,000,000.',
            'amount.integer' => 'Enter the amount in whole shillings.',
            'phone.required_unless' => 'Enter the mobile money phone number to pay from.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('phone')) {
                return;
            }

            if (UgandaPhone::normalise($this->input('phone')) === null) {
                $validator->errors()->add('phone', 'Enter a valid Ugandan mobile number, for example 0772 123456.');
            }
        });
    }

    /**
     * The phone number in +256 form, or null for a card payment without one.
     */
    public function normalisedPhone(): ?string
    {
        return UgandaPhone::normalise($this->input('phone'));
    }
}
