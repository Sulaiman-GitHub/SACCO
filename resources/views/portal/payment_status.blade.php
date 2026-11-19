@extends('layouts.portal')

@section('title', 'Your payment')
@section('page_title', 'Your payment')

@section('content')
@php
    $open = $payment->isOpen();
    $headline = match ($payment->status) {
        'successful' => 'Payment received',
        'failed' => 'Payment did not go through',
        'cancelled' => 'Payment cancelled',
        'expired' => 'Payment not completed',
        default => 'Waiting for your payment',
    };
@endphp
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="sacco-card text-center">
            <div id="payment-icon" style="font-size: 44px;" aria-hidden="true">
                @if($payment->isSuccessful())
                    <i class="bi bi-check-circle-fill" style="color: var(--color-risk-low);"></i>
                @elseif($open)
                    <i class="bi bi-hourglass-split" style="color: var(--color-risk-medium);"></i>
                @else
                    <i class="bi bi-x-circle-fill" style="color: var(--color-risk-high);"></i>
                @endif
            </div>
            <h2 id="payment-headline" style="font-size: 18px;" class="mt-2">{{ $headline }}</h2>
            <p id="payment-message" class="text-muted" style="font-size: 14px;" role="status" aria-live="polite">
                @if($payment->isSuccessful())
                    Payment received. Thank you.
                @elseif($open)
                    Waiting for your payment. If you were sent a prompt on your phone, please approve it.
                @elseif($payment->status === 'failed' && str_starts_with((string) $payment->failure_reason, 'Payment received'))
                    We received your payment but could not record it. Please contact your SACCO and quote this reference: {{ $payment->tx_ref }}
                @elseif($payment->status === 'failed')
                    The payment did not go through. You have not been charged for it.
                @elseif($payment->status === 'cancelled')
                    The payment was cancelled.
                @else
                    The payment was not completed in time.
                @endif
            </p>

            <ul class="list-unstyled text-start mx-auto" style="font-size: 13px; max-width: 360px;">
                <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Amount</span><strong>UGX {{ number_format($payment->amount) }}</strong></li>
                <li class="d-flex justify-content-between py-2 border-bottom"><span class="text-muted">Paid with</span><span>{{ $payment->networkLabel() }}</span></li>
                <li class="d-flex justify-content-between py-2"><span class="text-muted">Reference</span><span class="font-monospace">{{ $payment->tx_ref }}</span></li>
            </ul>

            <a id="payment-done" href="{{ $payment->purpose === 'repayment' ? route('portal.loans') : route('portal.savings') }}" class="btn btn-brand-primary btn-block-mobile mt-2" @if($open) hidden @endif>{{ $payment->purpose === 'repayment' ? 'Back to my loans' : 'Back to my savings' }}</a>
            <div class="text-muted mt-3" style="font-size: 12px;"><i class="bi bi-lock me-1"></i>Secured by Flutterwave</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@if($open)
<script>
    {{-- Ask our own server for the outcome until it is final; the page itself never decides anything. --}}
    (function () {
        var url = @json(route('portal.payments.status', $payment));
        var tries = 0;
        function apply(data) {
            document.getElementById('payment-message').textContent = data.message;
            if (!data.final) { return false; }
            var headlines = {successful: 'Payment received', failed: 'Payment did not go through', cancelled: 'Payment cancelled', expired: 'Payment not completed'};
            document.getElementById('payment-headline').textContent = headlines[data.status] || 'Payment finished';
            document.getElementById('payment-icon').innerHTML = data.status === 'successful'
                ? '<i class="bi bi-check-circle-fill" style="color: var(--color-risk-low);"></i>'
                : '<i class="bi bi-x-circle-fill" style="color: var(--color-risk-high);"></i>';
            document.getElementById('payment-done').hidden = false;
            return true;
        }
        function poll() {
            tries++;
            fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && apply(data)) { return; }
                    if (tries < 100) { setTimeout(poll, 4000); }
                })
                .catch(function () { if (tries < 100) { setTimeout(poll, 6000); } });
        }
        setTimeout(poll, 3000);
    })();
</script>
@endif
@endpush
