<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Member;
use App\Models\PaymentTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave API v3: hosted Standard checkout (mobile money and card) and transaction verification.
 * Card details never reach this application; the member pays on Flutterwave's own page.
 */
class FlutterwaveGateway implements PaymentGateway
{
    /**
     * @param  array{secret_key?: string, public_key?: string, encryption_key?: string}  $credentials
     */
    public function __construct(
        private array $credentials,
        private string $environment,
        private string $baseUrl,
        private int $timeout,
    ) {
        $secret = (string) ($credentials['secret_key'] ?? '');

        if ($secret === '') {
            throw new PaymentGatewayException('The Flutterwave secret key is not configured.');
        }

        if ($environment === 'live' && ! config('payments.allow_live')) {
            throw new PaymentGatewayException('Live payments are not enabled in this build. Use the sandbox.');
        }

        if ($environment !== 'live' && ! str_starts_with($secret, 'FLWSECK_TEST')) {
            throw new PaymentGatewayException('Sandbox mode needs a Flutterwave test secret key.');
        }
    }

    public function initiateCollection(PaymentTransaction $payment, Member $member): CollectionResult
    {
        $body = [
            'tx_ref' => $payment->tx_ref,
            'amount' => rtrim(rtrim(number_format((float) $payment->amount, 2, '.', ''), '0'), '.'),
            'currency' => $payment->currency,
            'redirect_url' => route('portal.payments.show', $payment),
            'payment_options' => $payment->network === 'card' ? 'card' : 'mobilemoneyuganda',
            'customer' => array_filter([
                'email' => $member->email ?: 'noreply@sacco.example',
                'phonenumber' => $payment->payer_phone,
                'name' => $member->full_name,
            ]),
            'customizations' => [
                'title' => $member->sacco?->name ?? 'SACCO',
                'description' => $payment->purpose === PaymentTransaction::PURPOSE_DEPOSIT ? 'Savings deposit' : 'Loan repayment',
            ],
            'meta' => ['purpose' => $payment->purpose],
        ];

        $response = $this->send(fn (PendingRequest $http) => $http->post($this->baseUrl.'/payments', $body));
        $link = $response->json('data.link');

        if ($response->failed() || $response->json('status') !== 'success' || ! is_string($link) || $link === '') {
            throw new PaymentGatewayException('Flutterwave did not start the payment: '.$this->errorMessage($response));
        }

        return new CollectionResult(checkoutUrl: $link, sanitized: ['status' => 'success']);
    }

    public function verify(PaymentTransaction $payment, ?string $providerTransactionId = null): VerificationResult
    {
        $response = $this->send(function (PendingRequest $http) use ($payment, $providerTransactionId) {
            if ($providerTransactionId !== null && ctype_digit($providerTransactionId)) {
                return $http->get($this->baseUrl.'/transactions/'.$providerTransactionId.'/verify');
            }

            return $http->get($this->baseUrl.'/transactions/verify_by_reference', ['tx_ref' => $payment->tx_ref]);
        });

        $data = $response->json('data');

        if (! is_array($data)) {
            // "No transaction was found" is normal for a payment the member has not started yet
            if ($response->serverError()) {
                throw new PaymentGatewayException('Flutterwave verification failed: '.$this->errorMessage($response));
            }

            return new VerificationResult(VerificationResult::NOT_FOUND);
        }

        $state = match (strtolower((string) ($data['status'] ?? ''))) {
            'successful', 'success' => VerificationResult::SUCCESSFUL,
            'failed' => VerificationResult::FAILED,
            'cancelled', 'canceled' => VerificationResult::CANCELLED,
            default => VerificationResult::PENDING,
        };

        return new VerificationResult(
            state: $state,
            txRef: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            providerReference: isset($data['flw_ref']) ? (string) $data['flw_ref'] : null,
            providerTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            fee: isset($data['app_fee']) ? (float) $data['app_fee'] : null,
            failureReason: $state === VerificationResult::FAILED ? (string) ($data['processor_response'] ?? 'The payment was not completed.') : null,
            // Whitelist only: no customer or card details are ever kept
            sanitized: Arr::only($data, ['id', 'tx_ref', 'flw_ref', 'status', 'amount', 'currency', 'charged_amount', 'app_fee', 'payment_type', 'processor_response', 'created_at']),
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $http = Http::withToken($this->credentials['secret_key'])
            ->acceptJson()
            ->timeout($this->timeout);

        try {
            return $call($http);
        } catch (ConnectionException) {
            // The message is deliberately generic: connection errors can echo request details
            throw new PaymentGatewayException('Could not reach Flutterwave.');
        }
    }

    private function errorMessage(Response $response): string
    {
        return (string) ($response->json('message') ?: 'HTTP '.$response->status());
    }
}
