<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\SaccoPaymentSetting;

/**
 * Builds the gateway for one SACCO from that SACCO's own stored settings.
 */
class PaymentGatewayManager
{
    public function isAvailableFor(int $saccoId): bool
    {
        return SaccoPaymentSetting::enabledFor($saccoId) !== null;
    }

    /**
     * @throws PaymentGatewayException
     */
    public function settingsFor(int $saccoId): SaccoPaymentSetting
    {
        return SaccoPaymentSetting::enabledFor($saccoId)
            ?? throw new PaymentGatewayException('Online payments are not available for this SACCO.');
    }

    /**
     * @throws PaymentGatewayException when payments are not set up for the SACCO or the settings are unusable
     */
    public function forSacco(int $saccoId): PaymentGateway
    {
        $settings = SaccoPaymentSetting::enabledFor($saccoId);

        if ($settings === null) {
            throw new PaymentGatewayException('Online payments are not available for this SACCO.');
        }

        return $this->make($settings);
    }

    public function make(SaccoPaymentSetting $settings): PaymentGateway
    {
        return match ($settings->provider) {
            'flutterwave' => new FlutterwaveGateway(
                (array) $settings->credentials,
                (string) $settings->environment,
                rtrim((string) config('payments.flutterwave.base_url'), '/'),
                (int) config('payments.flutterwave.timeout'),
            ),
            'fake' => new FakeGateway,
            default => throw new PaymentGatewayException('Unknown payment provider.'),
        };
    }
}
