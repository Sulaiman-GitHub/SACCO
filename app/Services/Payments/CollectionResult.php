<?php

namespace App\Services\Payments;

final class CollectionResult
{
    /**
     * @param  array<string, mixed>  $sanitized  Provider response with personal and card details removed
     */
    public function __construct(
        public readonly string $checkoutUrl,
        public readonly ?string $providerReference = null,
        public readonly array $sanitized = [],
    ) {}
}
