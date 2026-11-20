<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreRepaymentPaymentRequest;
use App\Models\Loan;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Members paying into their own account. Every query starts from the signed-in member, and the
 * browser never supplies a member, SACCO or ledger amount: it only asks for a payment to be started.
 */
class PortalPaymentController extends Controller
{
    public function createDeposit(Request $request, PaymentGatewayManager $gateways): View|RedirectResponse
    {
        $member = $request->user('member');

        if (! $gateways->isAvailableFor((int) $member->sacco_id)) {
            return redirect()->route('portal.savings')->with('error', 'Online payments are not available for your SACCO yet. Please pay at the SACCO.');
        }

        return view('portal.deposit', ['member' => $member]);
    }

    public function storeDeposit(StorePaymentRequest $request, PaymentProcessor $processor, PaymentGatewayManager $gateways): RedirectResponse
    {
        $member = $request->user('member');

        if (! $gateways->isAvailableFor((int) $member->sacco_id)) {
            return redirect()->route('portal.savings')->with('error', 'Online payments are not available for your SACCO yet. Please pay at the SACCO.');
        }

        return $this->startAndRedirect($request, $processor, PaymentTransaction::PURPOSE_DEPOSIT, (float) $request->validated('amount'), null);
    }

    public function createRepayment(Request $request, PaymentGatewayManager $gateways, string $loan): View|RedirectResponse
    {
        $member = $request->user('member');
        $ownLoan = $this->ownLoan($request, $loan);

        if (! $gateways->isAvailableFor((int) $member->sacco_id)) {
            return redirect()->route('portal.loans')->with('error', 'Online payments are not available for your SACCO yet. Please pay at the SACCO.');
        }

        if (! $ownLoan->canAcceptRepayment()) {
            return redirect()->route('portal.loans')->with('error', 'This loan has nothing left to pay.');
        }

        return view('portal.repayment', ['member' => $member, 'loan' => $ownLoan]);
    }

    public function storeRepayment(StoreRepaymentPaymentRequest $request, PaymentProcessor $processor, PaymentGatewayManager $gateways, string $loan): RedirectResponse
    {
        $member = $request->user('member');

        if (! $gateways->isAvailableFor((int) $member->sacco_id)) {
            return redirect()->route('portal.loans')->with('error', 'Online payments are not available for your SACCO yet. Please pay at the SACCO.');
        }

        // The amount was checked against the loan balance in the request; "pay everything" uses the server balance
        return $this->startAndRedirect($request, $processor, PaymentTransaction::PURPOSE_REPAYMENT, $request->amountToCollect(), $request->loanModel());
    }

    private function startAndRedirect(StorePaymentRequest $request, PaymentProcessor $processor, string $purpose, float $amount, ?Loan $loan): RedirectResponse
    {
        $member = $request->user('member');
        $network = $request->validated('network');

        try {
            // One start at a time per member, and a double click reuses the payment just started
            $payment = Cache::lock('payment-start:'.$member->id, 15)->block(5, function () use ($member, $amount, $network, $request, $processor, $purpose, $loan) {
                $recent = PaymentTransaction::withoutTenantScope()
                    ->where('sacco_id', $member->sacco_id)
                    ->where('member_id', $member->id)
                    ->where('purpose', $purpose)
                    ->where('loan_id', $loan?->id)
                    ->where('network', $network)
                    ->where('amount', $amount)
                    ->where('status', 'pending')
                    ->whereNotNull('checkout_url')
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->latest('id')
                    ->first();

                return $recent ?? $processor->start($member, $purpose, $amount, $network, $request->normalisedPhone(), $loan?->id);
            });
        } catch (PaymentGatewayException|LockTimeoutException) {
            return back()->withInput()->with('error', 'We could not start the payment just now. Nothing was charged. Please try again in a moment.');
        }

        return redirect()->away($payment->checkout_url);
    }

    /**
     * One of the signed-in member own loans, or a 404. The loan is never taken from anywhere else.
     */
    private function ownLoan(Request $request, string $loanId): Loan
    {
        $member = $request->user('member');

        return Loan::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->findOrFail($loanId);
    }

    /**
     * Where the member lands after paying: shows progress and keeps asking the server until it is final.
     */
    public function show(Request $request, PaymentProcessor $processor, string $payment): Response
    {
        $transaction = $this->ownPayment($request, $payment);
        $transaction = $this->refreshIfOpen($processor, $transaction, true);

        return response()
            ->view('portal.payment_status', ['payment' => $transaction])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Small status document for the status page to poll. It reveals nothing beyond the outcome.
     */
    public function status(Request $request, PaymentProcessor $processor, string $payment): JsonResponse
    {
        $transaction = $this->refreshIfOpen($processor, $this->ownPayment($request, $payment), false);

        return response()->json([
            'status' => $transaction->status,
            'final' => ! $transaction->isOpen(),
            'message' => $this->message($transaction),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function ownPayment(Request $request, string $txRef): PaymentTransaction
    {
        $member = $request->user('member');

        return PaymentTransaction::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->where('tx_ref', $txRef)
            ->firstOrFail();
    }

    private function refreshIfOpen(PaymentProcessor $processor, PaymentTransaction $payment, bool $force): PaymentTransaction
    {
        if (! $payment->isOpen()) {
            return $payment;
        }

        // At most one provider check every few seconds per payment, however often the page asks
        if ($force || Cache::add('payment-check:'.$payment->id, 1, 5)) {
            $payment = $processor->verifyAndSettle($payment);
            $payment = $processor->expireIfStale($payment);
        }

        return $payment;
    }

    private function message(PaymentTransaction $payment): string
    {
        return match ($payment->status) {
            'successful' => 'Payment received. Thank you.',
            'failed' => str_starts_with((string) $payment->failure_reason, 'Payment received')
                ? 'We received your payment but could not record it. Please contact your SACCO and quote this reference: '.$payment->tx_ref
                : 'The payment did not go through. You have not been charged for it.',
            'cancelled' => 'The payment was cancelled.',
            'expired' => 'The payment was not completed in time.',
            default => 'Waiting for your payment. If you were sent a prompt on your phone, please approve it.',
        };
    }
}
