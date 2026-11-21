@extends('layouts.portal')

@section('title', 'Withdrawal request')
@section('page_title', 'Withdrawal request')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <div>
                    <h2 class="font-monospace" style="font-size: 15px; margin: 0;">{{ $withdrawal->reference }}</h2>
                    <div class="text-muted" style="font-size: 12px;">Asked on {{ $withdrawal->created_at->format('d M Y') }}</div>
                </div>
                <span class="{{ $withdrawal->status === 'paid' ? 'badge-active' : (in_array($withdrawal->status, ['rejected', 'expired', 'cancelled']) ? 'badge-inactive' : 'badge-neutral') }}">{{ $withdrawal->statusLabel() }}</span>
            </div>

            <div class="row g-3 mb-3" style="font-size: 13px;">
                <div class="col-6"><div class="stat-title">Amount</div>UGX {{ number_format($withdrawal->amount, 2) }}</div>
                @if($withdrawal->early)
                    <div class="col-6"><div class="stat-title">Early withdrawal fee</div>UGX {{ number_format($withdrawal->fee_amount, 2) }}</div>
                @endif
                <div class="col-6"><div class="stat-title">You will receive</div><strong>UGX {{ number_format($withdrawal->net_amount, 2) }}</strong></div>
                <div class="col-6"><div class="stat-title">Paid to</div><span class="font-monospace">{{ $withdrawal->destination_phone }}</span></div>
                <div class="col-6"><div class="stat-title">Available from</div>{{ $withdrawal->available_from->format('d M Y') }}</div>
                @if($withdrawal->member_notes)
                    <div class="col-12"><div class="stat-title">Your note</div>{{ $withdrawal->member_notes }}</div>
                @endif
            </div>

            @if($withdrawal->status === 'pending')
                <div class="text-muted mb-3" style="font-size: 13px;">The SACCO has not reviewed your request yet.@if($withdrawal->available_from->isFuture()) It can be approved from {{ $withdrawal->available_from->format('d M Y') }}.@endif</div>
            @elseif($withdrawal->status === 'approved')
                <div class="text-muted mb-3" style="font-size: 13px;">Your request was approved on {{ $withdrawal->decided_at->format('d M Y') }}. The SACCO will pay you and record it here.</div>
            @elseif($withdrawal->status === 'rejected')
                <div class="mb-3" style="font-size: 13px;"><strong>This request was not approved.</strong>
                    @if($withdrawal->decision_reason)<div class="text-muted">Reason given by the SACCO: {{ $withdrawal->decision_reason }}</div>@endif
                </div>
            @elseif($withdrawal->status === 'paid')
                <div class="mb-3" style="font-size: 13px;">Paid on {{ $withdrawal->paid_at->format('d M Y') }} by {{ $withdrawal->payoutMethodLabel() }}@if($withdrawal->payout_reference) (reference {{ $withdrawal->payout_reference }})@endif.</div>
            @elseif($withdrawal->status === 'expired')
                <div class="text-muted mb-3" style="font-size: 13px;">This request was not paid in time and has expired. You can ask again.</div>
            @elseif($withdrawal->status === 'cancelled')
                <div class="text-muted mb-3" style="font-size: 13px;">You cancelled this request.</div>
            @endif

            @if($withdrawal->isOpen())
                <form method="POST" action="{{ route('portal.withdrawals.cancel', $withdrawal->id) }}">
                    @csrf
                    <button type="submit" class="btn btn-brand-outline btn-block-mobile">Cancel this request</button>
                </form>
            @endif
        </div>

        <a href="{{ route('portal.withdrawals.index') }}" class="btn btn-brand-outline btn-block-mobile">All my withdrawal requests</a>
    </div>
</div>
@endsection
