@extends('layouts.app')

@section('title', 'Online Payments')
@section('page_title', 'Online Payments')

@section('content')
<div class="sacco-card mb-4 p-3">
    <form method="GET" action="{{ route('tenant.payments.index') }}" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search reference or member..." value="{{ $search }}">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm" aria-label="Status">
                <option value="">All statuses</option>
                @foreach(['pending' => 'Waiting', 'successful' => 'Received', 'failed' => 'Failed', 'cancelled' => 'Cancelled', 'expired' => 'Expired'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select name="purpose" class="form-select form-select-sm" aria-label="Payment for">
                <option value="">Savings and loans</option>
                <option value="deposit" @selected($purpose === 'deposit')>Savings</option>
                <option value="repayment" @selected($purpose === 'repayment')>Loan repayments</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brand-dark px-3"><i class="bi bi-funnel me-1"></i> Filter</button>
            @if($search !== '' || $status || $purpose)
                <a href="{{ route('tenant.payments.index') }}" class="btn btn-sm btn-brand-outline">Clear</a>
            @endif
        </div>
    </form>
</div>

<div class="sacco-card p-0">
    <div class="table-responsive">
        <table class="sacco-table table-stack">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>For</th>
                    <th class="text-end">Amount (UGX)</th>
                    <th>Paid with</th>
                    <th>Reference</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payments as $payment)
                    <tr>
                        <td data-label="Date">
                            <div style="font-size: 13px;">{{ $payment->created_at->format('d M Y') }}</div>
                            <div class="text-muted" style="font-size: 11px;">{{ $payment->created_at->format('H:i') }}</div>
                        </td>
                        <td data-label="Member">
                            @if($payment->member)
                                <a href="{{ route('tenant.members.show', $payment->member) }}" class="fw-semibold text-decoration-none text-dark">{{ $payment->member->full_name }}</a>
                                <div class="text-muted font-monospace" style="font-size: 11px;">{{ $payment->member->member_number }}</div>
                            @endif
                        </td>
                        <td data-label="For">
                            @if($payment->purpose === 'repayment' && $payment->loan)
                                <a href="{{ route('tenant.loans.show', $payment->loan) }}" class="text-decoration-none">Repayment {{ $payment->loan->loan_reference }}</a>
                            @else
                                {{ $payment->purpose === 'repayment' ? 'Loan repayment' : 'Savings' }}
                            @endif
                        </td>
                        <td data-label="Amount (UGX)" class="text-end font-monospace fw-semibold">{{ number_format($payment->amount, 2) }}</td>
                        <td data-label="Paid with">{{ $payment->networkLabel() }}</td>
                        <td data-label="Reference">
                            <div class="font-monospace" style="font-size: 11px;">{{ $payment->tx_ref }}</div>
                            @if($payment->provider_reference)
                                <div class="text-muted" style="font-size: 10px;">Provider ref: {{ $payment->provider_reference }}</div>
                            @endif
                        </td>
                        <td data-label="Status">
                            @if($payment->status === 'successful')
                                <span class="badge-active">Received</span>
                            @elseif($payment->isOpen())
                                <span class="badge" style="background: rgba(217, 143, 43, 0.15); color: var(--color-risk-medium); border: 1px solid rgba(217, 143, 43, 0.3);">Waiting</span>
                            @else
                                <span class="badge badge-inactive">{{ ucfirst($payment->status) }}</span>
                                @if($payment->failure_reason)
                                    <div class="text-muted mt-1" style="font-size: 11px;">{{ $payment->failure_reason }}</div>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td data-label="" colspan="7" class="text-center py-5 text-muted">No online payments yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $payments->links() }}</div>
</div>
@endsection
