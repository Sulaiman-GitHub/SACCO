@extends('layouts.app')

@section('title', 'Savings Ledger')
@section('page_title', 'Savings & Contributions Ledger')

@section('topbar_actions')
    <a href="{{ route('tenant.savings.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-circle me-1"></i> Record Transaction
    </a>
@endsection

@section('content')
{{-- Financial Summary Metrics --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Net Savings Portfolio</div>
            <div class="stat-value" style="color: var(--color-teal);">
                UGX {{ number_format($portfolioBalance) }}
            </div>
            <div class="stat-desc">Computed: Deposits − Withdrawals</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Lifetime Deposits</div>
            <div class="stat-value" style="color: var(--color-risk-low);">
                UGX {{ number_format($totalDeposits) }}
            </div>
            <div class="stat-desc">Cumulative member contributions</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Lifetime Withdrawals</div>
            <div class="stat-value" style="color: var(--color-risk-high);">
                UGX {{ number_format($totalWithdrawals) }}
            </div>
            <div class="stat-desc">Cumulative disbursements to members</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Deposits This Month</div>
            <div class="stat-value text-dark">
                UGX {{ number_format($monthDeposits) }}
            </div>
            <div class="stat-desc">{{ number_format($transactionCount) }} total transactions</div>
        </div>
    </div>
</div>

{{-- Ledger Search and Filters --}}
<div class="sacco-card mb-4">
    <form method="GET" action="{{ route('tenant.savings.index') }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Search Reference / Member</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="search" name="search" class="form-control border-start-0" placeholder="Ref, name, or phone..." value="{{ request('search') }}">
                </div>
            </div>

            <div class="col-md-2">
                <label for="type" class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Type</label>
                <select id="type" name="type" class="form-select">
                    <option value="all" {{ request('type') === 'all' || !request('type') ? 'selected' : '' }}>All Types</option>
                    <option value="deposit" {{ request('type') === 'deposit' ? 'selected' : '' }}>Deposit (+)</option>
                    <option value="withdrawal" {{ request('type') === 'withdrawal' ? 'selected' : '' }}>Withdrawal (−)</option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="payment_method" class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Payment Method</label>
                <select id="payment_method" name="payment_method" class="form-select">
                    <option value="all" {{ request('payment_method') === 'all' || !request('payment_method') ? 'selected' : '' }}>All Methods</option>
                    <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Cash</option>
                    <option value="mtn_momo" {{ request('payment_method') === 'mtn_momo' ? 'selected' : '' }}>MTN MoMo</option>
                    <option value="airtel_money" {{ request('payment_method') === 'airtel_money' ? 'selected' : '' }}>Airtel Money</option>
                    <option value="bank_transfer" {{ request('payment_method') === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer</option>
                    <option value="other" {{ request('payment_method') === 'other' ? 'selected' : '' }}>Other</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Date Range</label>
                <div class="input-group">
                    <input type="date" name="start_date" class="form-control" value="{{ request('start_date') }}" title="Start Date">
                    <span class="input-group-text bg-light">to</span>
                    <input type="date" name="end_date" class="form-control" value="{{ request('end_date') }}" title="End Date">
                </div>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-brand-dark flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                @if(request()->hasAny(['search', 'type', 'payment_method', 'start_date', 'end_date']))
                    <a href="{{ route('tenant.savings.index') }}" class="btn btn-brand-outline" title="Reset Filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>
</div>

{{-- Append-Only Ledger Table --}}
<div class="sacco-card">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">Savings Transactions</h2>
            <div style="font-size: 12px; color: var(--color-slate);">
                Transactions cannot be edited or deleted &bull; Showing {{ $savings->firstItem() ?? 0 }} - {{ $savings->lastItem() ?? 0 }} of {{ $savings->total() }}
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border">
                <i class="bi bi-lock-fill me-1 text-success"></i> Permanent records
            </span>
        </div>
    </div>

    @if($savings->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-wallet2 text-muted" style="font-size: 40px;"></i>
            <h4 class="mt-3 mb-1" style="font-size: 16px;">No transactions found</h4>
            <p class="text-muted mb-3" style="font-size: 13px;">
                @if(request()->hasAny(['search', 'type', 'payment_method', 'start_date', 'end_date']))
                    No transactions match your filter criteria.
                @else
                    Record a member contribution to get started.
                @endif
            </p>
            <a href="{{ route('tenant.savings.create') }}" class="btn btn-sm btn-brand-primary">
                <i class="bi bi-plus-circle me-1"></i> Record First Transaction
            </a>
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table table-stack">
                <thead>
                    <tr>
                        <th>Txn Ref</th>
                        <th>Date</th>
                        <th>Member Details</th>
                        <th>Type</th>
                        <th class="text-end">Amount (UGX)</th>
                        <th>Payment Mode</th>
                        <th>Recorded By</th>
                        <th class="text-end">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($savings as $txn)
                        <tr>
                            <td data-label="Txn Ref">
                                <a href="{{ route('tenant.savings.show', $txn) }}" class="fw-bold font-monospace text-decoration-none text-dark" style="font-size: 12px;">
                                    {{ $txn->transaction_reference }}
                                </a>
                            </td>
                            <td data-label="Date">
                                <div style="font-size: 13px;">{{ $txn->transaction_date->format('d M Y') }}</div>
                                <div style="font-size: 10px; color: var(--color-slate);">{{ $txn->created_at->format('H:i') }}</div>
                            </td>
                            <td data-label="Member Details">
                                <a href="{{ route('tenant.members.show', $txn->member) }}" class="fw-semibold text-decoration-none text-dark">
                                    {{ $txn->member->full_name }}
                                </a>
                                <div style="font-size: 11px; color: var(--color-slate);">
                                    <code>{{ $txn->member->member_number }}</code> &bull; {{ $txn->member->phone }}
                                </div>
                            </td>
                            <td data-label="Type">
                                @if($txn->isDeposit())
                                    <span class="badge-active">
                                        <i class="bi bi-arrow-down-left me-1"></i> Deposit
                                    </span>
                                @else
                                    <span class="badge" style="background: rgba(217, 143, 43, 0.15); color: #B36B15; border: 1px solid rgba(217, 143, 43, 0.3); font-size: 11px; padding: 4px 8px;">
                                        <i class="bi bi-arrow-up-right me-1"></i> Withdrawal
                                    </span>
                                @endif
                            </td>
                            <td data-label="Amount (UGX)" class="text-end font-monospace fw-bold" style="font-size: 13px; color: {{ $txn->isDeposit() ? 'var(--color-risk-low)' : 'var(--color-risk-high)' }};">
                                {{ $txn->isDeposit() ? '+' : '−' }} {{ number_format($txn->amount, 2) }}
                            </td>
                            <td data-label="Payment Mode">
                                <span class="badge bg-light text-dark border font-monospace" style="font-size: 11px;">
                                    {{ strtoupper(str_replace('_', ' ', $txn->payment_method)) }}
                                </span>
                                @if($txn->payment_reference)
                                    <div style="font-size: 10px; color: var(--color-slate); margin-top: 2px;">
                                        Ref: {{ $txn->payment_reference }}
                                    </div>
                                @endif
                            </td>
                            <td data-label="Recorded By">
                                <div style="font-size: 12px;">{{ $txn->recorder->name ?? 'System' }}</div>
                            </td>
                            <td data-label="" class="text-end">
                                <a href="{{ route('tenant.savings.show', $txn) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="View Receipt">
                                    <i class="bi bi-receipt"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $savings->links() }}
        </div>
    @endif
</div>
@endsection
