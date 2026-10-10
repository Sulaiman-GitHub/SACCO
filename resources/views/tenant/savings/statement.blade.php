@extends('layouts.app')

@section('title', 'Savings Statement — ' . $member->full_name)
@section('page_title', 'Member Savings Passbook & Statement')

@section('topbar_actions')
    <a href="{{ route('tenant.members.show', $member) }}" class="btn btn-brand-outline">
        <i class="bi bi-person me-1"></i> Member Profile
    </a>
    <a href="{{ route('tenant.savings.create', ['member_id' => $member->id]) }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-circle me-1"></i> Record Deposit / Withdrawal
    </a>
    <button onclick="window.print()" class="btn btn-brand-dark">
        <i class="bi bi-printer me-1"></i> Print Statement
    </button>
@endsection

@section('styles')
<style>
@media print {
    .app-sidebar, .app-topbar, .btn, .no-print {
        display: none !important;
    }
    .app-shell, .app-main, .app-content {
        padding: 0 !important;
        margin: 0 !important;
        background: #FFFFFF !important;
    }
    .statement-card {
        border: none !important;
    }
}
</style>
@endsection

@section('content')
{{-- Member Statement Header --}}
<div class="sacco-card mb-4 statement-card">
    <div class="row align-items-center">
        <div class="col-md-7">
            <span class="badge bg-light text-dark border mb-2">Member Account Statement</span>
            <h2 style="font-size: 22px; margin: 0;">{{ $member->full_name }}</h2>
            <div class="text-muted mt-1" style="font-size: 13px;">
                Member No: <strong>{{ $member->member_number }}</strong> &bull;
                Phone: <strong>{{ $member->phone }}</strong> &bull;
                Joined: <strong>{{ $member->join_date?->format('d M Y') }} ({{ $member->tenure_months }} mo tenure)</strong>
            </div>
        </div>

        <div class="col-md-5 text-md-end mt-3 mt-md-0">
            <div class="stat-title">Current Available Savings Balance</div>
            <div class="fw-bold font-monospace fs-3" style="color: var(--color-teal);">
                UGX {{ number_format($runningBalance, 2) }}
            </div>
            <div style="font-size: 11px; color: var(--color-slate);">
                Deposits minus withdrawals
            </div>
        </div>
    </div>
</div>

{{-- Statement Summary Tiles --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Cumulative Deposits</div>
            <div class="stat-value font-monospace" style="color: var(--color-risk-low);">
                UGX {{ number_format($member->total_deposits, 2) }}
            </div>
            <div class="stat-desc">Total member savings contributions</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Cumulative Withdrawals</div>
            <div class="stat-value font-monospace" style="color: var(--color-risk-high);">
                UGX {{ number_format($member->total_withdrawals, 2) }}
            </div>
            <div class="stat-desc">Total savings payouts</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Total Transactions</div>
            <div class="stat-value">
                {{ $statementTransactions->count() }}
            </div>
            <div class="stat-desc">Complete transaction history</div>
        </div>
    </div>
</div>

{{-- Ledger Transactions Table --}}
<div class="sacco-card">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">Chronological Passbook History</h2>
            <div style="font-size: 12px; color: var(--color-slate);">Showing running balance computed after each transaction entry</div>
        </div>
        <span class="badge bg-light text-dark border">
            <i class="bi bi-shield-check text-success me-1"></i> Permanent records
        </span>
    </div>

    @if($statementTransactions->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-wallet2 text-muted" style="font-size: 40px;"></i>
            <h4 class="mt-3 mb-1" style="font-size: 16px;">No transactions recorded</h4>
            <p class="text-muted mb-3" style="font-size: 13px;">This member does not have any savings transactions yet.</p>
            <a href="{{ route('tenant.savings.create', ['member_id' => $member->id]) }}" class="btn btn-sm btn-brand-primary">
                <i class="bi bi-plus-circle me-1"></i> Record Opening Deposit
            </a>
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Description / Channel</th>
                        <th class="text-end">Deposit (+)</th>
                        <th class="text-end">Withdrawal (−)</th>
                        <th class="text-end">Running Balance (UGX)</th>
                        <th class="text-end no-print">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($statementTransactions as $txn)
                        <tr>
                            <td>
                                <div>{{ $txn->transaction_date->format('d M Y') }}</div>
                                <div style="font-size: 10px; color: var(--color-slate);">{{ $txn->created_at->format('H:i') }}</div>
                            </td>
                            <td>
                                <span class="font-monospace fw-semibold" style="font-size: 12px;">
                                    {{ $txn->transaction_reference }}
                                </span>
                            </td>
                            <td>
                                <div style="font-size: 13px;">
                                    {{ $txn->notes ?? ($txn->isDeposit() ? 'Savings Deposit' : 'Savings Withdrawal') }}
                                </div>
                                <div style="font-size: 11px; color: var(--color-slate);">
                                    Via {{ strtoupper(str_replace('_', ' ', $txn->payment_method)) }}
                                    @if($txn->payment_reference)
                                        &bull; <code>{{ $txn->payment_reference }}</code>
                                    @endif
                                </div>
                            </td>
                            <td class="text-end font-monospace" style="color: var(--color-risk-low); font-weight: {{ $txn->isDeposit() ? '700' : 'normal' }};">
                                {{ $txn->isDeposit() ? number_format($txn->amount, 2) : '—' }}
                            </td>
                            <td class="text-end font-monospace" style="color: var(--color-risk-high); font-weight: {{ $txn->isWithdrawal() ? '700' : 'normal' }};">
                                {{ $txn->isWithdrawal() ? number_format($txn->amount, 2) : '—' }}
                            </td>
                            <td class="text-end font-monospace fw-bold" style="color: var(--color-black); font-size: 13px;">
                                {{ number_format($txn->computed_balance, 2) }}
                            </td>
                            <td class="text-end no-print">
                                <a href="{{ route('tenant.savings.show', $txn) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="View Receipt">
                                    <i class="bi bi-receipt"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
