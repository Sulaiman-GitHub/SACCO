<?php

namespace App\Services\Payments;

final class VerificationResult
{
    public const SUCCESSFUL = 'successful';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const PENDING = 'pending';

    /** The provider has no record of the payment (yet). */
    public const NOT_FOUND = 'not_found';

    /**
     * @param  array<string, mixed>  $sanitized  Provider response with personal and card details removed
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $txRef = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $providerTransactionId = null,
        public readonly ?float $fee = null,
        public readonly ?string $failureReason = null,
        public readonly array $sanitized = [],
    ) {}
}
