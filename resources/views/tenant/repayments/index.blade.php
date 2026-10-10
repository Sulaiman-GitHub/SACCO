@extends('layouts.app')

@section('title', 'Repayments Ledger')
@section('page_title', 'Loan Repayments Ledger')

@section('topbar_actions')
    <a href="{{ route('tenant.loans.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-cash-stack me-1"></i> Loans Management
    </a>
    <a href="{{ route('tenant.repayments.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-lg me-1"></i> Record Repayment
    </a>
@endsection

@section('content')
{{-- Repayments Financial Metrics --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4">
        <div class="stat-tile">
            <div class="stat-title">Total Repayments Collected</div>
            <div class="stat-value" style="color: var(--color-green-start);">UGX {{ number_format($totalRepayments, 0) }}</div>
            <div class="stat-desc">Cumulative debt service collections</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="stat-tile">
            <div class="stat-title">Collections This Month</div>
            <div class="stat-value" style="color: var(--color-teal);">UGX {{ number_format($monthRepayments, 0) }}</div>
            <div class="stat-desc">Current calendar month recovery</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-4">
        <div class="stat-tile">
            <div class="stat-title">Installments Processed</div>
            <div class="stat-value">{{ number_format($repaymentCount) }}</div>
            <div class="stat-desc">Official receipts issued</div>
        </div>
    </div>
</div>

{{-- Search & Channel Filter --}}
<div class="sacco-card mb-4 p-3">
    <form method="GET" action="{{ route('tenant.repayments.index') }}" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search receipt ref, member, loan ref..." value="{{ $search }}">
            </div>
        </div>
        <div class="col-md-2">
            <select name="payment_method" class="form-select form-select-sm">
                <option value="all" {{ empty($paymentMethod) || $paymentMethod === 'all' ? 'selected' : '' }}>All Channels</option>
                <option value="cash" {{ $paymentMethod === 'cash' ? 'selected' : '' }}>Cash</option>
                <option value="mtn_momo" {{ $paymentMethod === 'mtn_momo' ? 'selected' : '' }}>MTN MoMo</option>
                <option value="airtel_money" {{ $paymentMethod === 'airtel_money' ? 'selected' : '' }}>Airtel Money</option>
                <option value="bank_transfer" {{ $paymentMethod === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer</option>
                <option value="other" {{ $paymentMethod === 'other' ? 'selected' : '' }}>Other</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="start_date" class="form-control form-control-sm" placeholder="Start Date" value="{{ $startDate }}">
        </div>
        <div class="col-md-2">
            <input type="date" name="end_date" class="form-control form-control-sm" placeholder="End Date" value="{{ $endDate }}">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brand-dark px-3 w-100">
                <i class="bi bi-funnel me-1"></i> Filter
            </button>
            @if(!empty($search) || !empty($paymentMethod) || !empty($startDate) || !empty($endDate))
                <a href="{{ route('tenant.repayments.index') }}" class="btn btn-sm btn-brand-outline">
                    <i class="bi bi-x"></i>
                </a>
            @endif
        </div>
    </form>
</div>

{{-- Ledger Table --}}
<div class="sacco-card p-0">
    <div class="table-responsive">
        <table class="sacco-table table-stack">
            <thead>
                <tr>
                    <th>Receipt Ref</th>
                    <th>Payment Date</th>
                    <th>Borrower</th>
                    <th>Loan Ref</th>
                    <th>Amount Paid</th>
                    <th>Channel & Txn Ref</th>
                    <th>Recorded By</th>
                    <th class="text-end">Receipt</th>
                </tr>
            </thead>
            <tbody>
                @forelse($repayments as $rep)
                    <tr>
                        <td data-label="Receipt Ref">
                            <a href="{{ route('tenant.repayments.show', $rep) }}" class="fw-bold font-monospace text-decoration-none" style="color: var(--color-black);">
                                {{ $rep->repayment_reference }}
                            </a>
                        </td>
                        <td data-label="Payment Date">{{ $rep->repayment_date->format('d M Y') }}</td>
                        <td data-label="Borrower">
                            <div class="fw-semibold text-dark">{{ $rep->member->full_name ?? '—' }}</div>
                            <div class="text-muted font-monospace" style="font-size: 11px;">
                                {{ $rep->member->member_number ?? '—' }}
                            </div>
                        </td>
                        <td data-label="Loan Ref">
                            @if($rep->loan)
                                <a href="{{ route('tenant.loans.show', $rep->loan) }}" class="font-monospace text-decoration-none fw-semibold">
                                    {{ $rep->loan->loan_reference }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td data-label="Amount Paid" class="font-monospace fw-bold text-success fs-6">
                            UGX {{ number_format($rep->amount, 2) }}
                        </td>
                        <td data-label="Channel & Txn Ref">
                            <div style="font-size: 12px;">{{ strtoupper(str_replace('_', ' ', $rep->payment_method)) }}</div>
                            @if($rep->payment_reference)
                                <div class="text-muted font-monospace" style="font-size: 11px;">Ref: {{ $rep->payment_reference }}</div>
                            @endif
                        </td>
                        <td data-label="Recorded By">
                            <span class="text-muted" style="font-size: 12px;">{{ $rep->recorder->name ?? 'System' }}</span>
                        </td>
                        <td data-label="" class="text-end">
                            <a href="{{ route('tenant.repayments.show', $rep) }}" class="btn btn-sm btn-brand-outline py-1 px-2" title="View Official Receipt">
                                <i class="bi bi-printer me-1"></i> Receipt
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td data-label="" colspan="8" class="text-center py-5">
                            <div class="text-muted mb-2"><i class="bi bi-journal-x fs-1"></i></div>
                            <div class="fw-bold text-dark fs-6">No repayment transactions found</div>
                            <div class="text-muted mb-3" style="font-size: 13px;">Record an installment payment to update a member's loan balance.</div>
                            <a href="{{ route('tenant.repayments.create') }}" class="btn btn-sm btn-brand-primary">
                                <i class="bi bi-plus-lg me-1"></i> Record Repayment
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($repayments->hasPages())
        <div class="p-3 border-top">
            {{ $repayments->links() }}
        </div>
    @endif
</div>
@endsection
