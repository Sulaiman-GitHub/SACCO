@extends('layouts.portal')

@section('title', 'Overview')
@section('page_title', 'Welcome, ' . $member->full_name)

@section('content')
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="sacco-card mb-0 h-100">
            <div class="stat-title">Savings balance</div>
            <div class="stat-value font-monospace" style="color: var(--color-teal);">UGX {{ number_format($member->savings_balance, 2) }}</div>
            <div class="text-muted mt-1" style="font-size: 12px;">
                Deposits UGX {{ number_format($member->total_deposits) }} &bull; Withdrawals UGX {{ number_format($member->total_withdrawals) }}
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="sacco-card mb-0 h-100">
            <div class="stat-title">Loan balance owed</div>
            <div class="stat-value font-monospace">UGX {{ number_format($member->total_loan_balance, 2) }}</div>
            <div class="text-muted mt-1" style="font-size: 12px;">{{ $activeLoanCount }} active of {{ $loanCount }} loan(s)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="sacco-card mb-0 h-100">
            <div class="stat-title">Membership</div>
            <div class="stat-value" style="font-size: 18px;">{{ $member->member_number }}</div>
            <div class="mt-1">
                <span class="{{ $member->isActive() ? 'badge-active' : 'badge-inactive' }}">{{ ucfirst($member->status) }}</span>
                <span class="text-muted ms-1" style="font-size: 12px;">{{ $member->tenure_months }} months</span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Recent savings</h2>
                <a href="{{ route('portal.savings') }}" class="btn btn-sm btn-brand-outline">All transactions</a>
            </div>
            @forelse($recentSavings as $txn)
                <div class="d-flex justify-content-between py-2 border-bottom" style="font-size: 13px;">
                    <div>
                        <span class="fw-bold {{ $txn->isDeposit() ? 'text-success' : 'text-warning' }}">{{ $txn->isDeposit() ? 'Deposit' : 'Withdrawal' }}</span>
                        <span class="text-muted ms-1">{{ $txn->transaction_date->format('d M Y') }}</span>
                    </div>
                    <div class="font-monospace fw-semibold">UGX {{ number_format($txn->amount) }}</div>
                </div>
            @empty
                <div class="text-muted" style="font-size: 13px;">No savings recorded yet.</div>
            @endforelse
        </div>
    </div>

    <div class="col-lg-5">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">My details</h2>
            </div>
            <ul class="list-unstyled mb-0" style="font-size: 13px;">
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Name</span><span class="fw-semibold">{{ $member->full_name }}</span></li>
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">National ID</span><span class="font-monospace">{{ $member->national_id_masked ?? 'Protected' }}</span></li>
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Phone</span><span>{{ $member->phone }}</span></li>
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Email</span><span>{{ $member->email ?? 'Not provided' }}</span></li>
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Address</span><span class="text-end">{{ $member->address ?? 'Not recorded' }}</span></li>
                <li class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Next of kin</span><span class="text-end">{{ $member->next_of_kin_name ?? 'None' }}</span></li>
                <li class="d-flex justify-content-between py-1"><span class="text-muted">Joined</span><span>{{ $member->join_date?->format('d M Y') }}</span></li>
            </ul>
            <div class="text-muted mt-3" style="font-size: 12px;">To correct any of these details, ask your SACCO. They cannot be changed here.</div>
        </div>

        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">My consent</h2>
                <a href="{{ route('portal.consent') }}" class="btn btn-sm btn-brand-outline">View</a>
            </div>
            <div style="font-size: 13px;">
                @if($pendingRequestCount > 0)
                    You have {{ $pendingRequestCount }} request(s) waiting for the SACCO.
                @else
                    See what you have agreed to, or ask the SACCO to change it.
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
