@extends('layouts.app')

@section('title', 'SACCO Workspace')
@section('page_title', 'Operational Dashboard')

@section('topbar_actions')
    <a href="{{ route('tenant.loans.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-cash-stack me-1"></i> New Loan
    </a>
    <a href="{{ route('tenant.savings.create') }}" class="btn btn-brand-outline">
        <i class="bi bi-plus-circle me-1"></i> Record Savings
    </a>
    <a href="{{ route('tenant.members.create') }}" class="btn btn-brand-outline">
        <i class="bi bi-person-plus me-1"></i> Register Member
    </a>
    @if(Auth::user()->isSaccoAdmin())
        <a href="{{ route('tenant.staff.create') }}" class="btn btn-brand-outline">
            <i class="bi bi-person-badge me-1"></i> Add Staff
        </a>
    @endif
@endsection

@section('content')
{{-- Welcome Banner --}}
<div class="sacco-card mb-4" style="background: linear-gradient(135deg, #0F172A 0%, #1A2127 100%); color: #FFFFFF; border: none; padding: 24px;">
    <div class="row align-items-center">
        <div class="col-12">
            <h2 class="text-white mb-1" style="font-size: 22px;">{{ $sacco->name }}</h2>
            <p class="text-white-50 mb-0" style="font-size: 13px;">
                License: <strong>{{ $sacco->license_number ?? 'In Progress' }}</strong> &bull;
                Classification: <strong>{{ $sacco->tier }}</strong> &bull;
                Signed in as: <strong>{{ $user->name }} ({{ ucfirst(str_replace('_', ' ', $user->role)) }})</strong>
            </p>
        </div>
    </div>
</div>

{{-- Stat Tiles --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Net Savings Portfolio</div>
            <div class="stat-value font-monospace" style="color: var(--color-teal); font-size: 20px;">
                UGX {{ number_format($portfolioBalance) }}
            </div>
            <div class="stat-desc">Computed: Deposits − Withdrawals</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Loan Portfolio Balance</div>
            <div class="stat-value font-monospace" style="color: var(--color-risk-low); font-size: 20px;">
                UGX {{ number_format($outstandingLoanDebt) }}
            </div>
            <div class="stat-desc">{{ $activeLoansCount }} active &bull; {{ $delinquentLoansCount }} delinquent</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Registered Members</div>
            <div class="stat-value">{{ number_format($membersCount) }}</div>
            <div class="stat-desc">{{ number_format($activeMembersCount) }} in active standing</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Authorized Staff</div>
            <div class="stat-value">{{ $staffCount }}</div>
            <div class="stat-desc">{{ $loanOfficersCount }} loan officers assigned</div>
        </div>
    </div>
</div>

{{-- Portfolio Health --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Deposits Today</div>
            <div class="stat-value font-monospace" style="color: var(--color-teal); font-size: 20px;">UGX {{ number_format($snapshot['deposits_today']) }}</div>
            <div class="stat-desc">Withdrawals today: UGX {{ number_format($snapshot['withdrawals_today']) }}</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Portfolio at Risk</div>
            @php
                $parColor = $snapshot['portfolio_at_risk_pct'] >= 20 ? 'var(--color-risk-high)' : ($snapshot['portfolio_at_risk_pct'] >= 5 ? 'var(--color-risk-medium)' : 'var(--color-risk-low)');
            @endphp
            <div class="stat-value" style="color: {{ $parColor }};">{{ number_format($snapshot['portfolio_at_risk_pct'], 1) }}%</div>
            <div class="stat-desc">UGX {{ number_format($snapshot['at_risk_balance']) }} overdue or defaulted</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Members in Arrears</div>
            <div class="stat-value">{{ $snapshot['at_risk_members']->pluck('member_id')->unique()->count() }}</div>
            <div class="stat-desc">{{ $snapshot['defaulted_loans'] }} defaulted loan(s)</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Awaiting Decision</div>
            <div class="stat-value">{{ $snapshot['pending_loans'] }}</div>
            <div class="stat-desc">Pending loan applications</div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Savings Contributions, Last 6 Months</h2>
            </div>
            <div style="position: relative; height: 260px;"><canvas id="contributionChart" aria-label="Monthly deposits and withdrawals"></canvas></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 16px; margin: 0;">Default Rate by Disbursement Month</h2>
                    <div style="font-size: 12px; color: var(--color-slate);">Share of that month's loans now defaulted</div>
                </div>
            </div>
            <div style="position: relative; height: 260px;"><canvas id="defaultRateChart" aria-label="Default rate by disbursement month"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Members in Arrears</h2>
            </div>
            @if($snapshot['at_risk_members']->isEmpty())
                <p class="text-muted mb-0" style="font-size: 13px;">No overdue or defaulted loans.</p>
            @else
                <div class="table-responsive">
                    <table class="sacco-table">
                        <thead><tr><th>Member</th><th>Status</th><th class="text-end">Days Overdue</th><th class="text-end">Balance (UGX)</th></tr></thead>
                        <tbody>
                            @foreach($snapshot['at_risk_members']->take(5) as $row)
                                <tr>
                                    <td><a href="{{ route('tenant.loans.show', $row['loan_id']) }}" class="text-dark fw-semibold text-decoration-none">{{ $row['name'] }}</a></td>
                                    <td><span class="badge" style="background: {{ $row['status'] === 'defaulted' ? 'var(--color-risk-high)' : 'var(--color-risk-medium)' }}; color: #fff;">{{ strtoupper($row['status']) }}</span></td>
                                    <td class="text-end font-monospace">{{ $row['days_overdue'] }}</td>
                                    <td class="text-end font-monospace fw-bold">{{ number_format($row['balance']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    <div class="col-lg-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 16px; margin: 0;">Highest Credit Risk Scores</h2>
                    <div style="font-size: 12px; color: var(--color-slate);">Open applications and active loans</div>
                </div>
            </div>
            @if($topRiskLoans->isEmpty())
                <p class="text-muted mb-0" style="font-size: 13px;">No scored loans yet.</p>
            @else
                <div class="table-responsive">
                    <table class="sacco-table">
                        <thead><tr><th>Member</th><th>Loan</th><th>Decision</th><th class="text-end">Score</th></tr></thead>
                        <tbody>
                            @foreach($topRiskLoans as $riskLoan)
                                @php
                                    $scoreColor = $riskLoan->risk_score >= 65 ? 'var(--color-risk-high)' : ($riskLoan->risk_score >= 35 ? 'var(--color-risk-medium)' : 'var(--color-risk-low)');
                                @endphp
                                <tr>
                                    <td>{{ $riskLoan->member->full_name ?? '—' }}</td>
                                    <td><a href="{{ route('tenant.loans.show', $riskLoan) }}" class="font-monospace text-decoration-none text-dark" style="font-size: 12px;">{{ $riskLoan->loan_reference }}</a></td>
                                    <td style="font-size: 12px;">{{ \App\Services\LendingDecisionEngine::label($riskLoan->decision) }}</td>
                                    <td class="text-end font-monospace fw-bold" style="color: {{ $scoreColor }};">{{ number_format($riskLoan->risk_score, 1) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Operational Split: Recent Members & Recent Savings Transactions --}}
<div class="row g-4 mb-4">
    {{-- Recent Savings Ledger Transactions --}}
    <div class="col-lg-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 16px; margin: 0;">Recent Savings Transactions</h2>
                    <div style="font-size: 12px; color: var(--color-slate);">Latest deposits and withdrawals</div>
                </div>
                <a href="{{ route('tenant.savings.index') }}" class="btn btn-sm btn-brand-outline">
                    View Ledger <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            @if($recentSavings->isEmpty())
                <div class="text-center py-4">
                    <i class="bi bi-wallet2 text-muted" style="font-size: 32px;"></i>
                    <p class="text-muted mt-2 mb-3" style="font-size: 13px;">No savings transactions recorded yet.</p>
                    <a href="{{ route('tenant.savings.create') }}" class="btn btn-brand-primary btn-sm">
                        <i class="bi bi-plus-circle me-1"></i> Record Opening Deposit
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="sacco-table">
                        <thead>
                            <tr>
                                <th>Ref & Date</th>
                                <th>Member</th>
                                <th>Type</th>
                                <th class="text-end">Amount (UGX)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentSavings as $txn)
                                <tr>
                                    <td>
                                        <a href="{{ route('tenant.savings.show', $txn) }}" class="fw-bold font-monospace text-decoration-none text-dark" style="font-size: 11px;">
                                            {{ $txn->transaction_reference }}
                                        </a>
                                        <div style="font-size: 11px; color: var(--color-slate);">{{ $txn->transaction_date->format('d M') }}</div>
                                    </td>
                                    <td>
                                        <a href="{{ route('tenant.members.show', $txn->member) }}" class="text-dark fw-semibold text-decoration-none" style="font-size: 12px;">
                                            {{ $txn->member->full_name }}
                                        </a>
                                    </td>
                                    <td>
                                        @if($txn->isDeposit())
                                            <span class="badge-active" style="font-size: 10px;">Deposit</span>
                                        @else
                                            <span class="badge" style="background: rgba(217, 143, 43, 0.15); color: #B36B15; font-size: 10px; padding: 3px 6px;">Withdrawal</span>
                                        @endif
                                    </td>
                                    <td class="text-end font-monospace fw-bold" style="font-size: 12px; color: {{ $txn->isDeposit() ? 'var(--color-risk-low)' : 'var(--color-risk-high)' }};">
                                        {{ $txn->isDeposit() ? '+' : '−' }} {{ number_format($txn->amount) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Recent Member Registrations --}}
    <div class="col-lg-6">
        <div class="sacco-card mb-0 h-100">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 16px; margin: 0;">Recent Member Registrations</h2>
                    <div style="font-size: 12px; color: var(--color-slate);">Latest additions to your SACCO directory</div>
                </div>
                <a href="{{ route('tenant.members.index') }}" class="btn btn-sm btn-brand-outline">
                    View All <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            @if($recentMembers->isEmpty())
                <div class="text-center py-4">
                    <i class="bi bi-people text-muted" style="font-size: 32px;"></i>
                    <p class="text-muted mt-2 mb-3" style="font-size: 13px;">No members registered yet in this SACCO.</p>
                    <a href="{{ route('tenant.members.create') }}" class="btn btn-brand-primary btn-sm">
                        <i class="bi bi-person-plus me-1"></i> Register First Member
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="sacco-table">
                        <thead>
                            <tr>
                                <th>Member No.</th>
                                <th>Full Name</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentMembers as $member)
                                <tr>
                                    <td><span class="fw-semibold text-dark">{{ $member->member_number }}</span></td>
                                    <td>{{ $member->full_name }}</td>
                                    <td><code>{{ $member->phone }}</code></td>
                                    <td>
                                        <span class="{{ $member->isActive() ? 'badge-active' : 'badge-inactive' }}">
                                            {{ ucfirst($member->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('tenant.members.show', $member) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="View Profile">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Operational Split 2: Recent Loan Applications & Portfolio Health --}}
<div class="sacco-card mb-4">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">Recent Loan Applications</h2>
            <div style="font-size: 12px; color: var(--color-slate);">Recent applications and active loans</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('tenant.repayments.index') }}" class="btn btn-sm btn-brand-outline">
                Repayments Ledger <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <a href="{{ route('tenant.loans.index') }}" class="btn btn-sm btn-brand-primary">
                View All Loans <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
    </div>

    @if($recentLoans->isEmpty())
        <div class="text-center py-4">
            <i class="bi bi-cash-stack text-muted" style="font-size: 32px;"></i>
            <p class="text-muted mt-2 mb-3" style="font-size: 13px;">No loan applications recorded yet.</p>
            <a href="{{ route('tenant.loans.create') }}" class="btn btn-brand-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Submit First Loan Application
            </a>
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table">
                <thead>
                    <tr>
                        <th>Loan Ref</th>
                        <th>Applicant</th>
                        <th>Principal</th>
                        <th>Term</th>
                        <th>Payable</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentLoans as $loan)
                        <tr>
                            <td>
                                <a href="{{ route('tenant.loans.show', $loan) }}" class="fw-bold font-monospace text-decoration-none text-dark">
                                    {{ $loan->loan_reference }}
                                </a>
                            </td>
                            <td>
                                <span class="fw-semibold">{{ $loan->member->full_name ?? '—' }}</span>
                                <span class="text-muted font-monospace" style="font-size: 11px;">({{ $loan->member->member_number ?? '—' }})</span>
                            </td>
                            <td class="font-monospace fw-semibold">UGX {{ number_format($loan->principal, 0) }}</td>
                            <td>{{ $loan->term_months }} mo @ {{ $loan->interest_rate }}%</td>
                            <td class="font-monospace fw-bold">UGX {{ number_format($loan->total_amount, 0) }}</td>
                            <td>
                                <span class="badge {{ $loan->isDisbursed() ? 'badge-active' : ($loan->isDefaulted() ? 'badge-inactive' : 'bg-secondary') }}">
                                    {{ strtoupper($loan->status) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('tenant.loans.show', $loan) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="View loan details">
                                    <i class="bi bi-eye"></i>
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

@section('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script>
    (function () {
        if (typeof Chart === 'undefined') { return; }

        const contributions = @json($contributionTrend);
        const defaults = @json($defaultRateTrend);
        const css = getComputedStyle(document.documentElement);
        const color = (name, fallback) => (css.getPropertyValue(name).trim() || fallback);
        const money = (v) => 'UGX ' + Number(v).toLocaleString();

        new Chart(document.getElementById('contributionChart'), {
            type: 'bar',
            data: {
                labels: contributions.labels,
                datasets: [
                    { label: 'Deposits', data: contributions.deposits, backgroundColor: color('--color-green', '#1FAE5C') },
                    { label: 'Withdrawals', data: contributions.withdrawals, backgroundColor: color('--color-teal', '#1B7A6E') }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + money(c.parsed.y) } } },
                scales: { y: { beginAtZero: true, ticks: { callback: (v) => window.innerWidth < 576 ? new Intl.NumberFormat('en', { notation: 'compact' }).format(v) : Number(v).toLocaleString() } } }
            }
        });

        new Chart(document.getElementById('defaultRateChart'), {
            type: 'line',
            data: {
                labels: defaults.labels,
                datasets: [{
                    label: 'Default rate (%)',
                    data: defaults.rates,
                    borderColor: color('--color-risk-high', '#C4432E'),
                    backgroundColor: color('--color-risk-high', '#C4432E'),
                    spanGaps: false,
                    tension: 0.2
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { afterLabel: (c) => defaults.disbursed[c.dataIndex] + ' loan(s) disbursed' } }
                },
                scales: { y: { beginAtZero: true, suggestedMax: 10, ticks: { callback: (v) => v + '%' } } }
            }
        });
    })();
</script>
@endsection
