<?php

namespace App\Services\Payments;

use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Saving;
use App\Services\AuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The one path every payment outcome goes through: the portal status page, the webhook and the
 * reconcile command all call verifyAndSettle(). Money is only ever recorded in the ledger from a
 * result the provider's verify API returned, matched against OUR payment row.
 */
class PaymentProcessor
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private AuditTrail $audit,
    ) {}

    /**
     * Create the payment row and ask the provider to start collecting. The member, SACCO and amount
     * are given by the caller from server-side data, never from a request or a webhook.
     *
     * @throws PaymentGatewayException
     */
    public function start(Member $member, string $purpose, float $amount, string $network, ?string $phone, ?int $loanId = null): PaymentTransaction
    {
        $saccoId = (int) $member->sacco_id;
        $gateway = $this->gateways->forSacco($saccoId);
        $settings = $this->gateways->settingsFor($saccoId);

        $payment = PaymentTransaction::withoutTenantScope()->create([
            'sacco_id' => $saccoId,
            'member_id' => $member->id,
            'loan_id' => $loanId,
            'purpose' => $purpose,
            'tx_ref' => PaymentTransaction::generateReference($saccoId),
            'provider' => $settings->provider,
            'amount' => round($amount, 2),
            'currency' => config('payments.currency'),
            'payer_phone' => $phone,
            'network' => $network,
            'status' => 'initiated',
        ]);

        $this->audit->record('payment.initiated', $payment, $this->auditDetails($payment));

        try {
            $collection = $gateway->initiateCollection($payment, $member);
        } catch (PaymentGatewayException $e) {
            $this->markFailed($payment, 'Could not start the payment with the provider.');
            Log::warning('Payment could not be started', ['tx_ref' => $payment->tx_ref, 'reason' => $e->getMessage()]);

            throw $e;
        }

        $payment->update([
            'status' => 'pending',
            'checkout_url' => $collection->checkoutUrl,
            'provider_reference' => $collection->providerReference,
            'provider_response' => $collection->sanitized ?: null,
        ]);

        return $payment;
    }

    /**
     * Ask the provider about an open payment and act on the answer. Safe to call as often and as
     * concurrently as you like: the ledger row is created at most once.
     */
    public function verifyAndSettle(PaymentTransaction $payment, ?string $providerTransactionId = null): PaymentTransaction
    {
        if ($payment->isSuccessful()) {
            return $payment;
        }

        try {
            $result = $this->gateways->forSacco((int) $payment->sacco_id)->verify($payment, $providerTransactionId);
        } catch (PaymentGatewayException $e) {
            Log::warning('Payment verification failed', ['tx_ref' => $payment->tx_ref, 'reason' => $e->getMessage()]);

            return $payment;
        }

        return $this->applyResult($payment, $result);
    }

    /**
     * Apply a verified provider result to OUR payment row.
     */
    public function applyResult(PaymentTransaction $payment, VerificationResult $result): PaymentTransaction
    {
        if ($result->state === VerificationResult::NOT_FOUND || $result->state === VerificationResult::PENDING) {
            return $payment;
        }

        // The provider must be talking about this very payment
        if ($result->txRef !== $payment->tx_ref) {
            Log::warning('Payment result ignored: reference mismatch', ['tx_ref' => $payment->tx_ref]);

            return $payment;
        }

        if ($result->state === VerificationResult::SUCCESSFUL) {
            if (! $this->matchesExpected($payment, $result)) {
                Log::warning('Payment result ignored: amount or currency mismatch', ['tx_ref' => $payment->tx_ref]);

                return $payment;
            }

            return $this->settle($payment, $result);
        }

        return $this->markFailed(
            $payment,
            $result->failureReason ?? 'The payment was not completed.',
            $result->state === VerificationResult::CANCELLED ? 'cancelled' : 'failed',
        );
    }

    /**
     * Mark open payments that were never completed as expired. A later successful confirmation from
     * the provider is still honoured, because the member's money was really taken.
     */
    public function expireIfStale(PaymentTransaction $payment): PaymentTransaction
    {
        $cutoff = Carbon::now()->subMinutes((int) config('payments.expire_after_minutes'));

        if (! $payment->isOpen() || $payment->created_at->gt($cutoff)) {
            return $payment;
        }

        return $this->markFailed($payment, 'The payment was not completed in time.', 'expired');
    }

    private function matchesExpected(PaymentTransaction $payment, VerificationResult $result): bool
    {
        return $result->amount !== null
            && abs($result->amount - (float) $payment->amount) < 0.005
            && strtoupper((string) $result->currency) === strtoupper($payment->currency);
    }

    private function settle(PaymentTransaction $payment, VerificationResult $result): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $result) {
            $locked = PaymentTransaction::withoutTenantScope()
                ->where('sacco_id', $payment->sacco_id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            // Already recorded by an earlier or concurrent confirmation
            if ($locked->isSuccessful() || $locked->saving_id !== null || $locked->repayment_id !== null) {
                return $locked;
            }

            try {
                $ledger = $this->recordInLedger($locked, $result);
            } catch (ValidationException $e) {
                // The money was taken but the ledger refused it (e.g. the member was deactivated).
                // Surface it for staff instead of retrying forever.
                $reason = 'Payment received but not recorded: '.collect($e->errors())->flatten()->first();
                $locked->update(['status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 255), 'provider_reference' => $result->providerReference, 'completed_at' => Carbon::now()]);
                $this->audit->record('payment.failed', $locked, $this->auditDetails($locked) + ['needs_attention' => true, 'reason' => $reason]);
                Log::error('Payment received but ledger refused it', ['tx_ref' => $locked->tx_ref]);

                return $locked;
            }

            $locked->forceFill([
                $ledger instanceof Saving ? 'saving_id' : 'repayment_id' => $ledger->getKey(),
                'status' => 'successful',
                'failure_reason' => null,
                'provider_reference' => $result->providerReference ?? $result->providerTransactionId,
                'fee' => $result->fee,
                'provider_response' => $result->sanitized ?: null,
                'completed_at' => Carbon::now(),
            ])->save();

            $this->audit->record('payment.succeeded', $locked, $this->auditDetails($locked));

            return $locked;
        });
    }

    private function recordInLedger(PaymentTransaction $payment, VerificationResult $result): Model
    {
        $reference = $result->providerReference ?? $result->providerTransactionId;

        return match ($payment->purpose) {
            PaymentTransaction::PURPOSE_DEPOSIT => Saving::recordLocked([
                'member_id' => $payment->member_id,
                'type' => 'deposit',
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->ledgerPaymentMethod(),
                'payment_reference' => $reference,
                'transaction_date' => Carbon::today()->toDateString(),
                'notes' => 'Paid online ('.$payment->tx_ref.')',
            ], (int) $payment->sacco_id, null),
            default => throw new PaymentGatewayException('Unsupported payment purpose.'),
        };
    }

    private function markFailed(PaymentTransaction $payment, string $reason, string $status = 'failed'): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $reason, $status) {
            $locked = PaymentTransaction::withoutTenantScope()
                ->where('sacco_id', $payment->sacco_id)
                ->lockForUpdate()
                ->findOrFail($payment->id);

            // Never downgrade a recorded payment, and never repeat a final outcome
            if ($locked->isSuccessful() || ! in_array($locked->status, [...PaymentTransaction::OPEN_STATUSES, 'failed', 'cancelled', 'expired'], true) || ($locked->status === $status)) {
                return $locked;
            }

            // A confirmed failure replaces an expiry; an expiry never replaces a confirmed failure
            if ($status === 'expired' && in_array($locked->status, ['failed', 'cancelled'], true)) {
                return $locked;
            }

            $locked->update(['status' => $status, 'failure_reason' => mb_substr($reason, 0, 255), 'completed_at' => Carbon::now()]);
            $this->audit->record($status === 'expired' ? 'payment.expired' : 'payment.failed', $locked, $this->auditDetails($locked) + ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function auditDetails(PaymentTransaction $payment): array
    {
        return [
            'tx_ref' => $payment->tx_ref,
            'purpose' => $payment->purpose,
            'amount' => (float) $payment->amount,
            'network' => $payment->network,
            'member_id' => $payment->member_id,
            'status' => $payment->status,
        ];
    }
}
