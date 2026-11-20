<?php

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A member paying towards one of their own loans. The loan comes from the route but is only ever
 * looked up among the signed-in member's loans, and the amount is checked against the balance the
 * server calculates, using the same rules the ledger applies when the money is recorded.
 */
class StoreRepaymentPaymentRequest extends StorePaymentRequest
{
    private ?Loan $loan = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pay_full' => ['nullable', 'boolean'],
            'amount' => ['required_without:pay_full', 'nullable', 'integer', 'min:1', 'max:10000000'],
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
            'amount.required_without' => 'Enter how much you want to pay, or choose to pay the full balance.',
            'amount.integer' => 'Enter the amount in whole shillings.',
            'amount.max' => 'The largest single online payment is UGX 10,000,000. You can pay the rest in another payment.',
            'phone.required_unless' => 'Enter the mobile money phone number to pay from.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $loan = $this->loanModel();

            if (! $loan->canAcceptRepayment()) {
                $validator->errors()->add('amount', 'This loan is not active or has nothing left to pay.');

                return;
            }

            $remaining = $loan->remaining_balance;
            $amount = $this->amountToCollect();

            if ($amount > 10000000) {
                $validator->errors()->add('amount', 'The largest single online payment is UGX 10,000,000. Pay part of the balance now and the rest in another payment.');
            } elseif ($amount > $remaining + 0.01) {
                $validator->errors()->add('amount', 'You cannot pay more than the balance owed, UGX '.number_format($remaining, 2).'.');
            } elseif (! Repayment::meetsMinimum($amount, $remaining)) {
                $validator->errors()->add('amount', 'The minimum payment is UGX '.number_format(Repayment::MINIMUM_AMOUNT).', unless you are paying the full balance of UGX '.number_format($remaining, 2).'.');
            }
        });
    }

    /**
     * The member's own loan from the route, or a 404.
     */
    public function loanModel(): Loan
    {
        if ($this->loan === null) {
            $member = $this->user('member');

            $this->loan = Loan::withoutTenantScope()
                ->where('sacco_id', $member->sacco_id)
                ->where('member_id', $member->id)
                ->findOrFail($this->route('loan'));
        }

        return $this->loan;
    }

    /**
     * What to collect: the typed amount, or the whole balance as the server calculates it.
     */
    public function amountToCollect(): float
    {
        return $this->boolean('pay_full')
            ? $this->loanModel()->remaining_balance
            : (float) $this->input('amount');
    }
}
