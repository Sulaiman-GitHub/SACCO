@extends('layouts.portal')

@section('title', 'Savings')
@section('page_title', 'My savings')

@section('content')
<div class="sacco-card">
    <div class="stat-title">Current balance</div>
    <div class="stat-value font-monospace" style="color: var(--color-teal);">UGX {{ number_format($member->savings_balance, 2) }}</div>
    <div class="text-muted mt-1" style="font-size: 12px;">
        Total deposits UGX {{ number_format($member->total_deposits) }} &bull; total withdrawals UGX {{ number_format($member->total_withdrawals) }}
    </div>
</div>

<div class="sacco-card">
    <div class="sacco-card-header">
        <h2 style="font-size: 15px; margin: 0;">Transaction history</h2>
    </div>
    {{-- Phones get a compact list; tablets and up get the full table. --}}
    <ul class="txn-list d-md-none">
        @forelse($transactions as $txn)
            <li>
                <div class="txn-main">
                    <span class="{{ $txn->isDeposit() ? 'badge-active' : 'badge-neutral' }}">{{ ucfirst($txn->type) }}</span>
                    <div class="mt-1">{{ $txn->transaction_date->format('d M Y') }}</div>
                    <div class="txn-meta">{{ $txn->transaction_reference }} &bull; {{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}</div>
                </div>
                <div class="txn-amount" style="color: {{ $txn->isDeposit() ? 'var(--color-risk-low)' : 'var(--color-slate)' }};">
                    {{ $txn->isDeposit() ? '+' : '−' }}{{ number_format($txn->amount) }}
                </div>
            </li>
        @empty
            <li class="text-muted">No savings recorded yet.</li>
        @endforelse
    </ul>

    <div class="table-responsive d-none d-md-block">
        <table class="sacco-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th class="text-end">Amount (UGX)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $txn)
                    <tr>
                        <td>{{ $txn->transaction_date->format('d M Y') }}</td>
                        <td class="font-monospace">{{ $txn->transaction_reference }}</td>
                        <td><span class="{{ $txn->isDeposit() ? 'badge-active' : 'badge-neutral' }}">{{ ucfirst($txn->type) }}</span></td>
                        <td>{{ ucwords(str_replace('_', ' ', $txn->payment_method)) }}</td>
                        <td class="text-end font-monospace fw-semibold">{{ $txn->isDeposit() ? '+' : '−' }}{{ number_format($txn->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No savings recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $transactions->links() }}</div>
</div>
@endsection
