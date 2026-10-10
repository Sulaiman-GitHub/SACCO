@extends('layouts.app')

@section('title', 'Loans & Credit Portfolio')
@section('page_title', 'Loans Management')

@section('topbar_actions')
    <a href="{{ route('tenant.repayments.create') }}" class="btn btn-brand-outline">
        <i class="bi bi-receipt me-1"></i> Record Repayment
    </a>
    <a href="{{ route('tenant.loans.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-lg me-1"></i> New Loan Application
    </a>
@endsection

@section('content')
{{-- Portfolio Summary Metric Cards --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Total Disbursed</div>
            <div class="stat-value">UGX {{ number_format($totalDisbursed, 0) }}</div>
            <div class="stat-desc">Cumulative principal lent out</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Outstanding Balance</div>
            <div class="stat-value" style="color: var(--color-teal);">UGX {{ number_format($totalOutstandingBalance, 0) }}</div>
            <div class="stat-desc">Principal & interest uncollected</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Active Loans</div>
            <div class="stat-value" style="color: var(--color-risk-low);">{{ number_format($activeLoansCount) }}</div>
            <div class="stat-desc">{{ $pendingLoansCount }} application(s) awaiting approval</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Delinquency Risk</div>
            <div class="stat-value" style="color: {{ ($overdueLoansCount + $defaultedLoansCount > 0) ? 'var(--color-risk-high)' : 'var(--color-risk-low)' }};">
                {{ $overdueLoansCount + $defaultedLoansCount }}
            </div>
            <div class="stat-desc">{{ $overdueLoansCount }} overdue &bull; {{ $defaultedLoansCount }} defaulted (&gt;90d)</div>
        </div>
    </div>
</div>

{{-- Search & Status Filter Box --}}
<div class="sacco-card mb-4 p-3">
    <form method="GET" action="{{ route('tenant.loans.index') }}" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search reference, member name, phone, or guarantor..." value="{{ $search }}">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
                <option value="all" {{ empty($status) || $status === 'all' ? 'selected' : '' }}>All Statuses</option>
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending Review</option>
                <option value="approved" {{ $status === 'approved' ? 'selected' : '' }}>Approved (Undisbursed)</option>
                <option value="disbursed" {{ $status === 'disbursed' ? 'selected' : '' }}>Disbursed / Performing</option>
                <option value="overdue" {{ $status === 'overdue' ? 'selected' : '' }}>Overdue (&le; 90 days)</option>
                <option value="defaulted" {{ $status === 'defaulted' ? 'selected' : '' }}>Defaulted (&gt; 90 days)</option>
                <option value="closed" {{ $status === 'closed' ? 'selected' : '' }}>Closed / Fully Repaid</option>
                <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Rejected</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brand-dark px-3">
                <i class="bi bi-funnel me-1"></i> Filter
            </button>
            @if(!empty($search) || !empty($status))
                <a href="{{ route('tenant.loans.index') }}" class="btn btn-sm btn-brand-outline">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </a>
            @endif
        </div>
    </form>
</div>

{{-- Loans Table --}}
<div class="sacco-card p-0">
    <div class="table-responsive">
        <table class="sacco-table table-stack">
            <thead>
                <tr>
                    <th>Loan Ref</th>
                    <th>Borrower</th>
                    <th>Principal</th>
                    <th>Terms</th>
                    <th>Total Payable</th>
                    <th>Repaid vs Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($loans as $loan)
                    <tr>
                        <td data-label="Loan Ref">
                            <a href="{{ route('tenant.loans.show', $loan) }}" class="fw-bold font-monospace text-decoration-none" style="color: var(--color-black);">
                                {{ $loan->loan_reference }}
                            </a>
                            <div class="text-muted" style="font-size: 11px;">
                                Applied: {{ $loan->applied_at ? $loan->applied_at->format('d M Y') : '—' }}
                            </div>
                        </td>
                        <td data-label="Borrower">
                            <div class="fw-semibold text-dark">{{ $loan->member->full_name ?? '—' }}</div>
                            <div class="text-muted font-monospace" style="font-size: 11px;">
                                {{ $loan->member->member_number ?? '—' }} &bull; {{ $loan->member->phone ?? '—' }}
                            </div>
                        </td>
                        <td data-label="Principal" class="font-monospace fw-semibold">
                            UGX {{ number_format($loan->principal, 0) }}
                        </td>
                        <td data-label="Terms">
                            <div style="font-size: 12px;">{{ $loan->term_months }} months @ {{ $loan->interest_rate }}%</div>
                            <div class="text-muted" style="font-size: 11px;">+UGX {{ number_format($loan->total_interest, 0) }} int.</div>
                        </td>
                        <td data-label="Total Payable" class="font-monospace fw-bold" style="color: var(--color-black);">
                            UGX {{ number_format($loan->total_amount, 0) }}
                        </td>
                        <td data-label="Repaid vs Balance" style="min-width: 170px;">
                            <div class="d-flex justify-content-between mb-1" style="font-size: 11px;">
                                <span class="text-muted">Repaid: UGX {{ number_format($loan->total_repaid, 0) }}</span>
                                <span class="fw-bold">{{ $loan->repayment_percentage }}%</span>
                            </div>
                            <div class="progress" style="height: 6px; background-color: #E8ECEF; border-radius: 2px;">
                                <div class="progress-bar" role="progressbar"
                                     style="width: {{ $loan->repayment_percentage }}%; background-color: {{ $loan->isClosed() ? 'var(--color-slate)' : 'var(--color-green-start)' }};"
                                     aria-valuenow="{{ $loan->repayment_percentage }}" aria-valuemin="0" aria-valuemax="100">
                                </div>
                            </div>
                            <div class="mt-1 font-monospace" style="font-size: 11px; color: {{ $loan->remaining_balance > 0 ? 'var(--color-teal)' : 'var(--color-slate)' }};">
                                Rem: UGX {{ number_format($loan->remaining_balance, 0) }}
                            </div>
                        </td>
                        <td data-label="Due Date">
                            @if($loan->due_date)
                                <div style="font-size: 12px; font-weight: 500;">
                                    {{ $loan->due_date->format('d M Y') }}
                                </div>
                                @if($loan->is_overdue)
                                    <div class="badge bg-danger text-white mt-1" style="font-size: 10px;">
                                        {{ $loan->days_overdue }} days past due
                                    </div>
                                @endif
                            @else
                                <span class="text-muted" style="font-size: 12px;">Not disbursed</span>
                            @endif
                        </td>
                        <td data-label="Status">
                            @if($loan->status === 'pending')
                                <span class="badge" style="background: rgba(217, 143, 43, 0.15); color: var(--color-risk-medium); border: 1px solid rgba(217, 143, 43, 0.3);">
                                    Pending Review
                                </span>
                            @elseif($loan->status === 'approved')
                                <span class="badge" style="background: rgba(27, 122, 110, 0.15); color: var(--color-teal); border: 1px solid rgba(27, 122, 110, 0.3);">
                                    Approved
                                </span>
                            @elseif($loan->status === 'disbursed')
                                <span class="badge badge-active">
                                    Disbursed
                                </span>
                            @elseif($loan->status === 'overdue')
                                <span class="badge" style="background: rgba(217, 143, 43, 0.2); color: var(--color-risk-medium); border: 1px solid var(--color-risk-medium);">
                                    Overdue
                                </span>
                            @elseif($loan->status === 'defaulted')
                                <span class="badge badge-inactive">
                                    Defaulted (&gt;90d)
                                </span>
                            @elseif($loan->status === 'closed')
                                <span class="badge" style="background: #E8ECEF; color: var(--color-slate); border: 1px solid #D8DCE0;">
                                    Closed (Paid)
                                </span>
                            @elseif($loan->status === 'rejected')
                                <span class="badge badge-inactive">
                                    Rejected
                                </span>
                            @endif
                        </td>
                        <td data-label="" class="text-end">
                            <a href="{{ route('tenant.loans.show', $loan) }}" class="btn btn-sm btn-brand-outline py-1 px-2" title="View Loan Details">
                                Details <i class="bi bi-chevron-right ms-1"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td data-label="" colspan="9" class="text-center py-5">
                            <div class="text-muted mb-2"><i class="bi bi-inbox fs-1"></i></div>
                            <div class="fw-bold text-dark fs-6">No loans matching your filter criteria</div>
                            <div class="text-muted mb-3" style="font-size: 13px;">Create a new loan application to begin tracking member credit.</div>
                            <a href="{{ route('tenant.loans.create') }}" class="btn btn-sm btn-brand-primary">
                                <i class="bi bi-plus-lg me-1"></i> New Loan Application
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loans->hasPages())
        <div class="p-3 border-top">
            {{ $loans->links() }}
        </div>
    @endif
</div>
@endsection
