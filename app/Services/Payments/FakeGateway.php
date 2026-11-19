<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Member;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;

/**
 * An in-memory gateway for local development and tests. A payment stays pending until
 * FakeGateway::complete() is called for its reference.
 */
class FakeGateway implements PaymentGateway
{
    public static function complete(string $txRef, string $state = VerificationResult::SUCCESSFUL): void
    {
        Cache::put('fake-gateway:'.$txRef, $state, 3600);
    }

    public function initiateCollection(PaymentTransaction $payment, Member $member): CollectionResult
    {
        return new CollectionResult(checkoutUrl: route('portal.payments.show', $payment), sanitized: ['status' => 'success']);
    }

    public function verify(PaymentTransaction $payment, ?string $providerTransactionId = null): VerificationResult
    {
        $state = Cache::get('fake-gateway:'.$payment->tx_ref);

        if ($state === null) {
            return new VerificationResult(VerificationResult::PENDING, txRef: $payment->tx_ref);
        }

        return new VerificationResult(
            state: $state,
            txRef: $payment->tx_ref,
            amount: (float) $payment->amount,
            currency: $payment->currency,
            providerReference: 'FAKE-'.$payment->id,
            providerTransactionId: (string) (900000 + $payment->id),
            fee: 0.0,
            failureReason: $state === VerificationResult::FAILED ? 'Declined (fake gateway).' : null,
            sanitized: ['status' => $state, 'tx_ref' => $payment->tx_ref],
        );
    }
}
