<?php

namespace App\Contracts;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Payments\CollectionResult;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\VerificationResult;

/**
 * Everything provider specific stays behind this interface. Switching provider (or Flutterwave API
 * version) means writing a new class, not touching controllers, the webhook or the ledger code.
 */
interface PaymentGateway
{
    /**
     * Ask the provider to start collecting the payment's amount from the member.
     *
     * @throws PaymentGatewayException
     */
    public function initiateCollection(PaymentTransaction $payment, Member $member): CollectionResult;

    /**
     * Ask the provider what really happened to a payment. Never trust a webhook body instead of this.
     *
     * @throws PaymentGatewayException
     */
    public function verify(PaymentTransaction $payment, ?string $providerTransactionId = null): VerificationResult;
}
