@extends('layouts.app')

@section('title', 'Reports')
@section('page_title', 'Management Reports')

@section('topbar_actions')
    <button type="button" class="btn btn-brand-outline" onclick="window.print()">
        <i class="bi bi-printer me-1"></i> Print / Save as PDF
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
    }
</style>
@endsection

@section('content')
<div class="sacco-card mb-4">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">{{ Auth::user()->sacco->name }} portfolio summary</h2>
            <div style="font-size: 12px; color: var(--color-slate);">Generated {{ $generatedAt->format('d M Y, H:i') }}</div>
        </div>
    </div>

    <table class="sacco-table">
        <tbody>
            <tr><td>Deposits today</td><td class="text-end font-monospace">UGX {{ number_format($snapshot['deposits_today']) }}</td></tr>
            <tr><td>Withdrawals today</td><td class="text-end font-monospace">UGX {{ number_format($snapshot['withdrawals_today']) }}</td></tr>
            <tr><td>Outstanding loan balance</td><td class="text-end font-monospace">UGX {{ number_format($snapshot['outstanding_balance']) }}</td></tr>
            <tr><td>Active loans</td><td class="text-end font-monospace">{{ $snapshot['active_loans'] }}</td></tr>
            <tr><td>Pending applications</td><td class="text-end font-monospace">{{ $snapshot['pending_loans'] }}</td></tr>
            <tr><td>Overdue or defaulted loans</td><td class="text-end font-monospace">{{ $snapshot['delinquent_loans'] }} ({{ $snapshot['defaulted_loans'] }} defaulted)</td></tr>
            <tr><td>Balance overdue or defaulted</td><td class="text-end font-monospace">UGX {{ number_format($snapshot['at_risk_balance']) }}</td></tr>
            <tr><td>Portfolio at risk</td><td class="text-end font-monospace fw-bold">{{ number_format($snapshot['portfolio_at_risk_pct'], 1) }}%</td></tr>
        </tbody>
    </table>
</div>

<div class="sacco-card mb-4">
    <div class="sacco-card-header">
        <h2 style="font-size: 16px; margin: 0;">Members in Arrears</h2>
    </div>
    @if($snapshot['at_risk_members']->isEmpty())
        <p class="text-muted mb-0" style="font-size: 13px;">No overdue or defaulted loans.</p>
    @else
        <table class="sacco-table">
            <thead><tr><th>Member</th><th>Status</th><th class="text-end">Days Overdue</th><th class="text-end">Balance (UGX)</th></tr></thead>
            <tbody>
                @foreach($snapshot['at_risk_members'] as $row)
                    <tr>
                        <td>{{ $row['name'] }} <span class="text-muted font-monospace" style="font-size: 11px;">{{ $row['member_number'] }}</span></td>
                        <td>{{ ucfirst($row['status']) }}</td>
                        <td class="text-end font-monospace">{{ $row['days_overdue'] }}</td>
                        <td class="text-end font-monospace">{{ number_format($row['balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="sacco-card mb-4">
    <div class="sacco-card-header">
        <h2 style="font-size: 16px; margin: 0;">Highest Credit Risk Scores</h2>
    </div>
    @if($topRiskLoans->isEmpty())
        <p class="text-muted mb-0" style="font-size: 13px;">No scored loans yet.</p>
    @else
        <table class="sacco-table">
            <thead><tr><th>Member</th><th>Loan</th><th>Status</th><th>Decision</th><th class="text-end">Score</th></tr></thead>
            <tbody>
                @foreach($topRiskLoans as $loan)
                    <tr>
                        <td>{{ $loan->member->full_name ?? '—' }}</td>
                        <td class="font-monospace">{{ $loan->loan_reference }}</td>
                        <td>{{ ucfirst($loan->status) }}</td>
                        <td>{{ \App\Services\LendingDecisionEngine::label($loan->decision) }}</td>
                        <td class="text-end font-monospace">{{ number_format($loan->risk_score, 1) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="row g-4 d-print-none">
    <div class="col-md-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Export savings ledger (CSV)</h2></div>
            <form method="GET" action="{{ route('tenant.reports.savings_csv') }}" class="row g-2 align-items-end">
                <div class="col-sm-5">
                    <label for="start_date" class="form-label" style="font-size: 12px;">From</label>
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm">
                </div>
                <div class="col-sm-5">
                    <label for="end_date" class="form-label" style="font-size: 12px;">To</label>
                    <input type="date" name="end_date" id="end_date" class="form-control form-control-sm">
                </div>
                <div class="col-sm-2"><button type="submit" class="btn btn-brand-primary btn-sm w-100">Export</button></div>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header"><h2 style="font-size: 16px; margin: 0;">Export loan portfolio (CSV)</h2></div>
            <form method="GET" action="{{ route('tenant.reports.loans_csv') }}" class="row g-2 align-items-end">
                <div class="col-sm-10">
                    <label for="status" class="form-label" style="font-size: 12px;">Status</label>
                    <select name="status" id="status" class="form-select form-select-sm">
                        <option value="">All loans</option>
                        @foreach(['pending', 'approved', 'rejected', 'disbursed', 'overdue', 'defaulted', 'closed'] as $status)
                            <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-2"><button type="submit" class="btn btn-brand-primary btn-sm w-100">Export</button></div>
            </form>
        </div>
    </div>
</div>
@endsection
