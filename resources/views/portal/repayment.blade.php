@extends('layouts.portal')

@section('title', 'Pay my loan')
@section('page_title', 'Pay my loan')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <div>
                    <h2 class="font-monospace" style="font-size: 15px; margin: 0;">{{ $loan->loan_reference }}</h2>
                    <div class="text-muted" style="font-size: 12px;">Balance owed: <strong>UGX {{ number_format($loan->remaining_balance, 2) }}</strong></div>
                </div>
            </div>

            <form method="POST" action="{{ route('portal.repayments.store', $loan->id) }}">
                @csrf

                <div class="mb-3">
                    <label for="amount" class="form-label">Amount (UGX)</label>
                    <input type="number" inputmode="numeric" min="1" step="1" class="form-control @error('amount') is-invalid @enderror" id="amount" name="amount" value="{{ old('amount') }}">
                    <div class="form-text">At least UGX {{ number_format(\App\Models\Repayment::MINIMUM_AMOUNT) }}, and no more than you owe.</div>
                    @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="pay_full" name="pay_full" value="1" @checked(old('pay_full'))>
                    <label for="pay_full" class="form-check-label">Pay the full balance (UGX {{ number_format($loan->remaining_balance, 2) }})</label>
                </div>

                <div class="stat-title mb-2">How do you want to pay?</div>
                @foreach(['mtn' => 'MTN Mobile Money', 'airtel' => 'Airtel Money', 'card' => 'Bank card'] as $value => $label)
                    <label class="choice" for="network_{{ $value }}">
                        <input type="radio" name="network" id="network_{{ $value }}" value="{{ $value }}" {{ old('network', 'mtn') === $value ? 'checked' : '' }} required>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                @error('network') <div class="text-danger mb-2" style="font-size: 13px;">{{ $message }}</div> @enderror

                <div class="mb-3 mt-3">
                    <label for="phone" class="form-label">Mobile money phone number</label>
                    <input type="tel" inputmode="tel" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $member->phone) }}" placeholder="0772 123456" autocomplete="tel">
                    <div class="form-text">Not needed for a card payment.</div>
                    @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <button type="submit" class="btn btn-brand-primary btn-block-mobile">Continue to payment</button>
                <div class="text-muted mt-2 text-center" style="font-size: 12px;"><i class="bi bi-lock me-1"></i>Secured by Flutterwave. We never see or keep your card details.</div>
            </form>
        </div>
    </div>
</div>
@endsection
