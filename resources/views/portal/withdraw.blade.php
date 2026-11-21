@extends('layouts.portal')

@section('title', 'Withdraw savings')
@section('page_title', 'Withdraw savings')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        {{-- The rules the SACCO has set, in plain words, shown before the member asks --}}
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Before you ask</h2>
            </div>
            <ul class="mb-0 ps-3" style="font-size: 13px;">
                <li>Your request is reviewed by the SACCO and paid to your registered phone number. You will see its progress under "My withdrawal requests".</li>
                <li>
                    @if($policy->notice_days > 0)
                        Please give {{ $policy->notice_days }} day{{ $policy->notice_days === 1 ? '' : 's' }} notice. The money can be paid out once the notice period has passed.
                    @else
                        No notice period is needed.
                    @endif
                </li>
                <li>
                    @if($policy->loan_cover_mode === \App\Models\WithdrawalPolicy::COVER_KEEP_RATIO)
                        While you have a loan, enough savings must stay in your account to keep covering it.
                    @else
                        Savings back your loan, so they cannot be withdrawn while you have a loan that is not fully repaid.
                    @endif
                </li>
                @if($policy->offersEarlyWithdrawal())
                    <li>You can withdraw before the notice period ends for a fee of {{ rtrim(rtrim(number_format($policy->early_withdrawal_fee_percent, 2), '0'), '.') }}% of the amount, taken from what is paid to you.</li>
                @endif
                @if($policy->daily_limit_per_member !== null)
                    <li>You can withdraw up to UGX {{ number_format($policy->daily_limit_per_member) }} in one day.</li>
                @endif
                @if($policy->security_hold_days > 0)
                    <li>If you change your password or phone number, withdrawals are paused for {{ $policy->security_hold_days }} day{{ $policy->security_hold_days === 1 ? '' : 's' }} to keep your savings safe.</li>
                @endif
                <li>Only one request can be open at a time. You can cancel it until it is paid.</li>
            </ul>
        </div>

        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Ask to withdraw</h2>
            </div>

            <div class="mb-3">
                <div class="stat-title">You can ask for up to</div>
                <div class="stat-value font-monospace" style="color: var(--color-teal);">UGX {{ number_format(max(0, $available), 2) }}</div>
            </div>

            @if($openRequest)
                <div class="alert alert-warning" style="font-size: 13px;">
                    You already have a request waiting ({{ $openRequest->reference }}).
                    <a href="{{ route('portal.withdrawals.show', $openRequest->id) }}">See it here</a>.
                </div>
            @elseif(! $hasPhone)
                <div class="alert alert-warning" style="font-size: 13px;">There is no valid mobile number on your record, so we cannot pay you. Please ask your SACCO to add one.</div>
            @elseif($holdEndsAt)
                <div class="alert alert-warning" style="font-size: 13px;">For your safety, withdrawals are paused after a password or phone number change. You can ask again from {{ $holdEndsAt->format('d M Y') }}.</div>
            @else
                <form method="POST" action="{{ route('portal.withdrawals.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="amount" class="form-label">Amount (UGX)</label>
                        <input type="number" inputmode="decimal" min="1" step="any" class="form-control @error('amount') is-invalid @enderror" id="amount" name="amount" value="{{ old('amount') }}" required>
                        <div class="form-text">At least UGX {{ number_format(\App\Models\Saving::MINIMUM_AMOUNT) }}, or your whole balance.</div>
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    @if($policy->offersEarlyWithdrawal())
                        <label class="choice mb-3" for="early">
                            <input type="checkbox" name="early" id="early" value="1" @checked(old('early'))>
                            <span>I want it sooner, without waiting for the notice period. A fee of {{ rtrim(rtrim(number_format($policy->early_withdrawal_fee_percent, 2), '0'), '.') }}% will be taken from what I am paid.</span>
                        </label>
                        <div class="text-muted mb-3" style="font-size: 12px;">Example: for UGX 100,000 the fee is UGX {{ number_format($policy->earlyFeeFor(100000)) }} and you receive UGX {{ number_format(100000 - $policy->earlyFeeFor(100000)) }}. The exact amounts are shown on your request.</div>
                        @error('early') <div class="text-danger mb-2" style="font-size: 13px;">{{ $message }}</div> @enderror
                    @endif

                    <div class="mb-3">
                        <label class="form-label">Paid to</label>
                        <div class="form-control-plaintext font-monospace">{{ \App\Support\UgandaPhone::normalise($member->phone) }}</div>
                        <div class="form-text">Your registered phone number. To change it, please visit your SACCO.</div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Note for the SACCO (optional)</label>
                        <input type="text" maxlength="500" class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" value="{{ old('notes') }}">
                        @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Your password</label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" autocomplete="current-password" required>
                        <div class="form-text">Enter your portal password to confirm it is you.</div>
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="btn btn-brand-primary btn-block-mobile">Send request</button>
                </form>
            @endif
        </div>

        <a href="{{ route('portal.withdrawals.index') }}" class="btn btn-brand-outline btn-block-mobile">My withdrawal requests</a>
    </div>
</div>
@endsection
