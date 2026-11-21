@extends('layouts.app')

@section('title', 'Withdrawal ' . $withdrawal->reference)
@section('page_title', 'Withdrawal Request: ' . $withdrawal->reference)

@section('content')
@php($user = Auth::user())
<div class="row g-4">
    <div class="col-lg-7">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Request details</h2>
                <span class="{{ $withdrawal->status === 'paid' ? 'badge-active' : (in_array($withdrawal->status, ['rejected', 'expired', 'cancelled']) ? 'badge-inactive' : 'badge-neutral') }}">{{ $withdrawal->statusLabel() }}</span>
            </div>
            <div class="row g-3" style="font-size: 13px;">
                <div class="col-sm-6"><div class="stat-title">Member</div><a href="{{ route('tenant.members.show', $member) }}" class="text-decoration-none">{{ $member->full_name }}</a> <span class="text-muted font-monospace">{{ $member->member_number }}</span></div>
                <div class="col-sm-6"><div class="stat-title">Paid to (phone on file when asked)</div><span class="font-monospace">{{ $withdrawal->destination_phone }}</span></div>
                <div class="col-sm-6"><div class="stat-title">Amount withdrawn from savings</div><strong>UGX {{ number_format($withdrawal->amount, 2) }}</strong></div>
                <div class="col-sm-6"><div class="stat-title">Amount to pay the member</div><strong>UGX {{ number_format($withdrawal->net_amount, 2) }}</strong>@if($withdrawal->early) <div class="text-muted">after an early withdrawal fee of UGX {{ number_format($withdrawal->fee_amount, 2) }}</div>@endif</div>
                <div class="col-sm-6"><div class="stat-title">Available from</div>{{ $withdrawal->available_from->format('d M Y') }}@if($withdrawal->early) <span class="text-muted">(early withdrawal)</span>@endif</div>
                <div class="col-sm-6"><div class="stat-title">Current savings balance</div>UGX {{ number_format($balance, 2) }}</div>
                @if($withdrawal->member_notes)<div class="col-12"><div class="stat-title">Member's note</div>{{ $withdrawal->member_notes }}</div>@endif
            </div>
        </div>

        <div class="sacco-card">
            <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Loans that savings back</h2></div>
            @forelse($loans as $loan)
                <div class="d-flex justify-content-between" style="font-size: 13px;">
                    <a href="{{ route('tenant.loans.show', $loan) }}" class="font-monospace text-decoration-none">{{ $loan->loan_reference }}</a>
                    <span>{{ ucfirst($loan->status) }} &bull; principal UGX {{ number_format($loan->principal, 2) }} &bull; owed UGX {{ number_format($loan->remaining_balance, 2) }}</span>
                </div>
            @empty
                <div class="text-muted" style="font-size: 13px;">The member has no outstanding loans.</div>
            @endforelse
        </div>

        <div class="sacco-card">
            <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Timeline</h2></div>
            <ul class="list-unstyled mb-0" style="font-size: 13px;">
                <li>Asked by the member on {{ $withdrawal->created_at->format('d M Y, H:i') }}</li>
                @if($withdrawal->decided_at)
                    <li>{{ $withdrawal->status === 'rejected' ? 'Rejected' : 'Approved' }} by {{ $withdrawal->decider?->name ?? 'staff' }} on {{ $withdrawal->decided_at->format('d M Y, H:i') }}@if($withdrawal->decision_reason): "{{ $withdrawal->decision_reason }}"@endif</li>
                @endif
                @if($withdrawal->paid_at)
                    <li>Paid by {{ $withdrawal->payer?->name ?? 'staff' }} on {{ $withdrawal->paid_at->format('d M Y, H:i') }} ({{ $withdrawal->payoutMethodLabel() }}@if($withdrawal->payout_reference), reference {{ $withdrawal->payout_reference }}@endif)
                        @if($withdrawal->ledgerEntry) &bull; <a href="{{ route('tenant.savings.show', $withdrawal->ledgerEntry) }}">{{ $withdrawal->ledgerEntry->transaction_reference }}</a>@endif</li>
                @endif
                @if($withdrawal->cancelled_at)<li>Cancelled by the member on {{ $withdrawal->cancelled_at->format('d M Y, H:i') }}</li>@endif
                @if($withdrawal->expired_at)<li>Expired on {{ $withdrawal->expired_at->format('d M Y, H:i') }}</li>@endif
            </ul>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="sacco-card">
            <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Rules applied</h2></div>
            <ul class="mb-0 ps-3" style="font-size: 13px;">
                <li>Notice: {{ $policy->notice_days }} day(s)</li>
                <li>{{ \App\Models\WithdrawalPolicy::COVER_MODE_LABELS[$policy->loan_cover_mode] }}</li>
                <li>Early withdrawal fee: {{ $policy->early_withdrawal_fee_percent > 0 ? rtrim(rtrim(number_format($policy->early_withdrawal_fee_percent, 2), '0'), '.').'%' : 'not offered' }}</li>
                <li>Daily limit: {{ $policy->daily_limit_per_member !== null ? 'UGX '.number_format($policy->daily_limit_per_member) : 'none' }}</li>
                <li>Administrator approval: {{ $policy->admin_approval_threshold !== null ? 'above UGX '.number_format($policy->admin_approval_threshold) : 'always' }}
                    @if($needsAdmin) <strong>(needed for this request)</strong>@endif</li>
            </ul>
        </div>

        @if($withdrawal->isOpen())
            @if($moneyProblem)
                <div class="alert alert-warning" style="font-size: 13px;">Right now this cannot go ahead: {{ $moneyProblem }}</div>
            @endif

            @if($withdrawal->status === 'pending')
                <div class="sacco-card">
                    <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Decision</h2></div>
                    @if($withdrawal->available_from->isFuture())
                        <div class="text-muted mb-3" style="font-size: 13px;">This can be approved from {{ $withdrawal->available_from->format('d M Y') }}.</div>
                    @elseif($needsAdmin && ! $user->isSaccoAdmin())
                        <div class="text-muted mb-3" style="font-size: 13px;">A SACCO administrator must approve a request of this size.</div>
                    @else
                        <form method="POST" action="{{ route('tenant.withdrawals.approve', $withdrawal) }}" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-brand-primary">Approve request</button>
                        </form>
                    @endif
                </div>
            @endif

            @if($withdrawal->status === 'approved')
                <div class="sacco-card">
                    <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Record payout</h2></div>
                    @if($payerBlock)
                        <div class="alert alert-warning mb-0" style="font-size: 13px;">{{ $payerBlock }}</div>
                    @else
                    <p class="text-muted" style="font-size: 12px;">Pay UGX {{ number_format($withdrawal->net_amount, 2) }} to {{ $withdrawal->destination_phone }} yourself first, then record it here. This adds the withdrawal to the savings records.</p>
                    <form method="POST" action="{{ route('tenant.withdrawals.pay', $withdrawal) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="payout_method" class="form-label">Paid by</label>
                            <select name="payout_method" id="payout_method" class="form-select @error('payout_method') is-invalid @enderror" required>
                                @foreach(\App\Models\WithdrawalRequest::PAYOUT_METHODS as $value => $label)
                                    <option value="{{ $value }}" @selected(old('payout_method') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="payout_reference" class="form-label">Transaction reference</label>
                            <input type="text" name="payout_reference" id="payout_reference" maxlength="100" class="form-control @error('payout_reference') is-invalid @enderror" value="{{ old('payout_reference') }}">
                            <div class="form-text">Not needed for cash.</div>
                        </div>
                        <button type="submit" class="btn btn-brand-primary">Record payout</button>
                    </form>
                    @endif
                </div>
            @endif

            <div class="sacco-card">
                <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Reject</h2></div>
                <form method="POST" action="{{ route('tenant.withdrawals.reject', $withdrawal) }}">
                    @csrf
                    <div class="mb-3">
                        <label for="decision_reason" class="form-label">Reason (the member will see it)</label>
                        <textarea name="decision_reason" id="decision_reason" rows="2" maxlength="500" class="form-control @error('decision_reason') is-invalid @enderror" required>{{ old('decision_reason') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-danger">Reject request</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
