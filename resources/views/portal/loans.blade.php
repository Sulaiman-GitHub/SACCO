@extends('layouts.portal')

@section('title', 'Loans')
@section('page_title', 'My loans')

@section('content')
@forelse($loans as $loan)
    <div class="sacco-card">
        <div class="sacco-card-header">
            <div>
                <h2 class="font-monospace" style="font-size: 15px; margin: 0;">{{ $loan->loan_reference }}</h2>
                <div class="text-muted" style="font-size: 12px;">Applied {{ $loan->applied_at->format('d M Y') }}</div>
            </div>
            <span class="{{ in_array($loan->status, ['overdue', 'defaulted']) ? 'badge-inactive' : (in_array($loan->status, ['disbursed', 'approved']) ? 'badge-active' : 'badge-neutral') }}">
                {{ strtoupper($loan->status) }}
            </span>
        </div>

        <div class="row g-3 mb-3" style="font-size: 13px;">
            <div class="col-6 col-md-3"><div class="stat-title">Principal</div>UGX {{ number_format($loan->principal, 2) }}</div>
            <div class="col-6 col-md-3"><div class="stat-title">Total to repay</div>UGX {{ number_format($loan->total_amount, 2) }}</div>
            <div class="col-6 col-md-3"><div class="stat-title">Repaid so far</div>UGX {{ number_format($loan->total_repaid, 2) }}</div>
            <div class="col-6 col-md-3"><div class="stat-title">Balance owed</div><strong>UGX {{ number_format($loan->remaining_balance, 2) }}</strong></div>
            <div class="col-6 col-md-3"><div class="stat-title">Term</div>{{ $loan->term_months }} months</div>
            <div class="col-6 col-md-3"><div class="stat-title">Due date</div>{{ $loan->due_date?->format('d M Y') ?? 'Not yet set' }}</div>
        </div>

        @if($loan->isRejected())
            <div class="text-muted mb-3" style="font-size: 12px;">This application was not approved. Ask your SACCO if you would like to know why.</div>
        @endif

        <div class="stat-title">Repayments</div>
        @if($loan->repayments->isNotEmpty())
            <ul class="txn-list d-md-none">
                @foreach($loan->repayments->sortByDesc('repayment_date') as $repayment)
                    <li>
                        <div class="txn-main">
                            <div>{{ $repayment->repayment_date->format('d M Y') }}</div>
                            <div class="txn-meta">{{ $repayment->repayment_reference }} &bull; {{ ucwords(str_replace('_', ' ', $repayment->payment_method)) }}</div>
                        </div>
                        <div class="txn-amount">{{ number_format($repayment->amount) }}</div>
                    </li>
                @endforeach
            </ul>

            <div class="table-responsive d-none d-md-block">
                <table class="sacco-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Method</th>
                            <th class="text-end">Amount (UGX)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($loan->repayments->sortByDesc('repayment_date') as $repayment)
                            <tr>
                                <td>{{ $repayment->repayment_date->format('d M Y') }}</td>
                                <td class="font-monospace">{{ $repayment->repayment_reference }}</td>
                                <td>{{ ucwords(str_replace('_', ' ', $repayment->payment_method)) }}</td>
                                <td class="text-end font-monospace fw-semibold">{{ number_format($repayment->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-muted" style="font-size: 13px;">No repayments recorded for this loan.</div>
        @endif
    </div>
@empty
    <div class="sacco-card text-muted text-center">You have no loans on record.</div>
@endforelse
@endsection
