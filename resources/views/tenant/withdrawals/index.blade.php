@extends('layouts.app')

@section('title', 'Withdrawal Requests')
@section('page_title', 'Withdrawal Requests')

@section('content')
<div class="sacco-card mb-4 p-3">
    <form method="GET" action="{{ route('tenant.withdrawals.index') }}" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search reference or member..." value="{{ $search }}">
            </div>
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm" aria-label="Status">
                <option value="">All statuses</option>
                @foreach(\App\Models\WithdrawalRequest::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brand-dark px-3"><i class="bi bi-funnel me-1"></i> Filter</button>
            @if($search !== '' || $status)
                <a href="{{ route('tenant.withdrawals.index') }}" class="btn btn-sm btn-brand-outline">Clear</a>
            @endif
        </div>
    </form>
</div>

<div class="sacco-card p-0">
    <div class="px-3 pt-3 text-muted" style="font-size: 13px;">{{ $pendingCount }} request(s) waiting for review.</div>
    <div class="table-responsive">
        <table class="sacco-table table-stack">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Member</th>
                    <th class="text-end">Amount (UGX)</th>
                    <th>Available from</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $item)
                    <tr>
                        <td data-label="Reference">
                            <a href="{{ route('tenant.withdrawals.show', $item) }}" class="font-monospace text-decoration-none">{{ $item->reference }}</a>
                            <div class="text-muted" style="font-size: 11px;">Asked {{ $item->created_at->format('d M Y') }}</div>
                        </td>
                        <td data-label="Member">
                            @if($item->member)
                                {{ $item->member->full_name }}
                                <div class="text-muted font-monospace" style="font-size: 11px;">{{ $item->member->member_number }}</div>
                            @endif
                        </td>
                        <td data-label="Amount (UGX)" class="text-end font-monospace fw-semibold">{{ number_format($item->amount, 2) }}</td>
                        <td data-label="Available from">{{ $item->available_from->format('d M Y') }}@if($item->early) <span class="text-muted">(early)</span>@endif</td>
                        <td data-label="Status"><span class="{{ $item->status === 'paid' ? 'badge-active' : (in_array($item->status, ['rejected', 'expired', 'cancelled']) ? 'badge-inactive' : 'badge-neutral') }}">{{ $item->statusLabel() }}</span></td>
                    </tr>
                @empty
                    <tr><td data-label="" colspan="5" class="text-center py-5 text-muted">No withdrawal requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $requests->links() }}</div>
</div>
@endsection
