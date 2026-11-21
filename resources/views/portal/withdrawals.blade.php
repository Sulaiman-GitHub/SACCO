@extends('layouts.portal')

@section('title', 'My withdrawal requests')
@section('page_title', 'My withdrawal requests')

@section('content')
<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="{{ route('portal.withdrawals.create') }}" class="btn btn-brand-primary btn-block-mobile"><i class="bi bi-box-arrow-up me-1"></i> Withdraw savings</a>
    <a href="{{ route('portal.savings') }}" class="btn btn-brand-outline btn-block-mobile">Back to savings</a>
</div>

<div class="sacco-card">
    <ul class="txn-list">
        @forelse($requests as $item)
            <li>
                <div class="txn-main">
                    <a href="{{ route('portal.withdrawals.show', $item->id) }}" class="font-monospace text-decoration-none">{{ $item->reference }}</a>
                    <div class="txn-meta">Asked on {{ $item->created_at->format('d M Y') }}</div>
                    <div class="mt-1">
                        <span class="{{ $item->status === 'paid' ? 'badge-active' : (in_array($item->status, ['rejected', 'expired', 'cancelled']) ? 'badge-inactive' : 'badge-neutral') }}">{{ $item->statusLabel() }}</span>
                    </div>
                </div>
                <div class="txn-amount">UGX {{ number_format($item->amount) }}</div>
            </li>
        @empty
            <li class="text-muted">You have not asked to withdraw any savings yet.</li>
        @endforelse
    </ul>
    <div class="mt-3">{{ $requests->links() }}</div>
</div>
@endsection
