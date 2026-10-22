@extends('layouts.app')

@section('title', $member->full_name . ' (' . $member->member_number . ')')
@section('page_title', 'Member Profile & KYC Record')

@section('topbar_actions')
    <a href="{{ route('tenant.members.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Members
    </a>
    <a href="{{ route('tenant.members.edit', $member) }}" class="btn btn-brand-dark">
        <i class="bi bi-pencil me-1"></i> Edit Member
    </a>
    @if(Auth::user()->isSaccoAdmin())
        <form method="POST" action="{{ route('tenant.members.toggle_status', $member) }}" class="d-inline m-0" onsubmit="return confirm('Are you sure you want to {{ $member->isActive() ? 'deactivate' : 'activate' }} this member?');">
            @csrf
            <button type="submit" class="btn {{ $member->isActive() ? 'btn-outline-danger' : 'btn-outline-success' }}">
                <i class="bi {{ $member->isActive() ? 'bi-person-dash' : 'bi-person-check' }} me-1"></i>
                {{ $member->isActive() ? 'Deactivate Member' : 'Activate Member' }}
            </button>
        </form>
    @endif
@endsection

@section('content')
{{-- Member Header Card --}}
<div class="sacco-card mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; background: #0F172A; color: #FFFFFF; font-size: 20px; font-weight: 700;">
                    {{ strtoupper(substr($member->full_name, 0, 1)) }}
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h2 style="font-size: 22px; margin: 0;">{{ $member->full_name }}</h2>
                        @if($member->status === 'active')
                            <span class="badge-active">Active Member</span>
                        @elseif($member->status === 'suspended')
                            <span class="badge-inactive">Suspended</span>
                        @else
                            <span class="badge-inactive">Inactive</span>
                        @endif
                    </div>
                    <div class="text-muted" style="font-size: 13px;">
                        Member ID: <strong>{{ $member->member_number }}</strong> &bull;
                        SACCO: <strong>{{ $member->sacco->name ?? 'Current SACCO' }}</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <div class="stat-title">Membership Tenure</div>
            <div class="d-flex align-items-center justify-content-md-end gap-2">
                <span class="fs-4 fw-bold text-dark">{{ $member->tenure_months }}</span>
                <span class="text-muted" style="font-size: 13px;">months</span>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Left Column: KYC and Bio-data --}}
    <div class="col-lg-7">
        {{-- Identity & Bio-Data --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Identity & Verified Bio-Data</h2>
                <span class="badge bg-light text-dark border">
                    <i class="bi bi-shield-check text-success me-1"></i> Protected under the Data Protection Act
                </span>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="stat-title">National ID / NIN (Masked)</div>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <i class="bi bi-shield-lock-fill text-success fs-5"></i>
                        <span class="font-monospace fw-bold" style="font-size: 14px; letter-spacing: 0.05em;">
                            {{ $member->national_id_masked ?? 'Protected' }}
                        </span>
                    </div>
                    <div class="text-muted mt-1" style="font-size: 11px;">
                        The full ID number is never stored or shown.
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="stat-title">Primary Telephone</div>
                    <div class="fw-semibold mt-1">
                        <code>{{ $member->phone }}</code>
                    </div>
                    <div class="text-muted mt-1" style="font-size: 11px;">
                        Mobile Money & SMS notification line
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="stat-title">Email Address</div>
                    <div class="fw-semibold mt-1">
                        {{ $member->email ?? 'Not provided' }}
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="stat-title">Gender & Date of Birth</div>
                    <div class="fw-semibold mt-1">
                        {{ ucfirst($member->gender ?? 'Not specified') }}
                        @if($member->date_of_birth)
                            &bull; {{ $member->date_of_birth->format('d M Y') }} ({{ $member->date_of_birth->age }} yrs)
                        @endif
                    </div>
                </div>

                <div class="col-12">
                    <div class="stat-title">Residential Address / Trading Centre</div>
                    <div class="fw-semibold mt-1">
                        {{ $member->address ?? 'Not recorded' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Economic & Livelihood Profile --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Economic Profile & Income</h2>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="stat-title">Occupation / Livelihood</div>
                    <div class="fw-semibold mt-1 fs-6">
                        {{ $member->occupation ?? 'General Member' }}
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="stat-title">Estimated Monthly Income</div>
                    <div class="fw-bold mt-1 fs-5 text-dark">
                        {{ $member->monthly_income ? 'UGX ' . number_format($member->monthly_income) : 'Not specified' }}
                    </div>
                </div>

                @if($member->notes)
                    <div class="col-12">
                        <div class="stat-title">Internal Membership Notes</div>
                        <div class="p-2 border rounded bg-light mt-1" style="font-size: 13px;">
                            {{ $member->notes }}
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Next of Kin Card --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Next of Kin / Designated Contact</h2>
            </div>

            <div class="row g-3">
                <div class="col-sm-4">
                    <div class="stat-title">Full Name</div>
                    <div class="fw-semibold mt-1">
                        {{ $member->next_of_kin_name ?? 'None' }}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="stat-title">Relationship</div>
                    <div class="fw-semibold mt-1">
                        {{ $member->next_of_kin_relationship ?? 'None' }}
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="stat-title">Contact Phone</div>
                    <div class="fw-semibold mt-1">
                        {{ $member->next_of_kin_phone ? $member->next_of_kin_phone : 'None' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Right Column: Membership Metadata & Future Modules Integration --}}
    <div class="col-lg-5">
        {{-- Membership Metadata --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Membership Record</h2>
            </div>

            <ul class="list-unstyled mb-0" style="font-size: 13px;">
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Enrollment Date:</span>
                    <span class="fw-semibold">{{ $member->join_date?->format('d M Y') }}</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Tenure in SACCO:</span>
                    <span class="fw-semibold">{{ $member->tenure_months }} Months</span>
                </li>
                <li class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted">Registered By Staff:</span>
                    <span class="fw-semibold">{{ $member->creator->name ?? 'System Administrator' }}</span>
                </li>
                <li class="d-flex justify-content-between py-2">
                    <span class="text-muted">Registered On:</span>
                    <span class="fw-semibold">{{ $member->created_at?->format('d M Y, H:i') }}</span>
                </li>
            </ul>
        </div>

        {{-- Consent Record --}}
        <div class="sacco-card mb-4" style="border-left: 3px solid {{ $member->hasRecordedConsent() ? 'var(--color-teal)' : 'var(--color-risk-medium)' }};">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Consent Record</h2>
                <span class="badge bg-light text-dark border">
                    <i class="bi bi-journal-check me-1"></i> Data Protection Act
                </span>
            </div>

            @if($member->hasRecordedConsent())
                <ul class="list-unstyled mb-3" style="font-size: 13px;">
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Credit scoring:</span>
                        <span class="fw-semibold">{{ $member->scoring_consent ? 'Agreed' : 'Declined' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Next of kin informed:</span>
                        <span class="fw-semibold">{{ $member->next_of_kin_informed ? 'Confirmed' : 'Not confirmed' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Notice version:</span>
                        <span class="fw-semibold">{{ $member->consent_version }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-1">
                        <span class="text-muted">Captured:</span>
                        <span class="fw-semibold">{{ $member->consented_at?->format('d M Y, H:i') }} by {{ $member->consentCapturer->name ?? 'staff' }}</span>
                    </li>
                </ul>
            @else
                <p class="mb-3" style="font-size: 13px;">No consent has been recorded for this member. They are not credit scored until it is captured, and loan applications go to an officer to decide manually. Record it below.</p>
            @endif

            <details {{ $errors->has('consent_data_processing') || $errors->has('scoring_consent') ? 'open' : '' }}>
                <summary class="btn btn-sm btn-brand-outline">{{ $member->hasRecordedConsent() ? 'Update or withdraw consent' : 'Record consent' }}</summary>
                <form method="POST" action="{{ route('tenant.members.update_consent', $member) }}" class="mt-3">
                    @csrf
                    @include('tenant.members._consent_fields')
                    <button type="submit" class="btn btn-brand-primary btn-sm mt-3">Save consent</button>
                </form>
            </details>
        </div>

        {{-- Consent change requests sent from the member portal --}}
        @php
            $consentRequests = $member->consentChangeRequests()->with('handler')->take(10)->get();
        @endphp
        @if($consentRequests->isNotEmpty())
            <div class="sacco-card mb-4" style="border-left: 3px solid var(--color-risk-medium);">
                <div class="sacco-card-header">
                    <h2 style="font-size: 15px; margin: 0;">Consent Requests From Member</h2>
                    <span class="badge bg-light text-dark border">Portal</span>
                </div>
                @foreach($consentRequests as $consentRequest)
                    <div class="py-2 border-bottom" style="font-size: 13px;">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold">{{ $consentRequest->label() }}</span>
                            <span class="badge {{ $consentRequest->isPending() ? 'bg-warning text-dark' : 'bg-secondary' }}">{{ ucfirst($consentRequest->status) }}</span>
                        </div>
                        <div class="text-muted" style="font-size: 12px;">Sent {{ $consentRequest->created_at->format('d M Y, H:i') }}</div>
                        @if($consentRequest->member_note)
                            <div style="font-size: 12px;">Member note: {{ $consentRequest->member_note }}</div>
                        @endif
                        @if($consentRequest->isPending())
                            <form method="POST" action="{{ route('tenant.members.consent_requests.resolve', [$member, $consentRequest]) }}" class="mt-2">
                                @csrf
                                <input type="text" name="handler_note" class="form-control form-control-sm mb-2" placeholder="Note to the member (required if dismissing)" maxlength="500">
                                <button type="submit" name="outcome" value="processed" class="btn btn-sm btn-brand-primary">Mark processed</button>
                                <button type="submit" name="outcome" value="dismissed" class="btn btn-sm btn-brand-outline">Dismiss</button>
                                <div class="text-muted mt-1" style="font-size: 11px;">Make the change with the consent form above first, then mark it processed.</div>
                            </form>
                        @else
                            <div class="text-muted" style="font-size: 12px;">
                                {{ ucfirst($consentRequest->status) }} by {{ $consentRequest->handler->name ?? 'staff' }} on {{ $consentRequest->handled_at?->format('d M Y') }}
                                @if($consentRequest->handler_note) &bull; {{ $consentRequest->handler_note }} @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Member Portal Access --}}
        <div class="sacco-card mb-4" style="border-left: 3px solid {{ $member->portal_enabled ? 'var(--color-teal)' : 'var(--color-slate)' }};">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Member Portal Access</h2>
                <span class="badge {{ $member->portal_enabled ? 'bg-success' : 'bg-secondary' }}">{{ $member->portal_enabled ? 'Enabled' : 'Off' }}</span>
            </div>

            @if(session('portal_credentials'))
                <div class="alert alert-warning" style="font-size: 13px;">
                    <div class="fw-bold mb-1">Give these to the member now. The password cannot be shown again.</div>
                    Login name: <code>{{ session('portal_credentials')['username'] }}</code><br>
                    Temporary password: <code>{{ session('portal_credentials')['password'] }}</code>
                    <div class="mt-1" style="font-size: 12px;">They will be asked to choose their own password when they first sign in at <code>{{ route('portal.login') }}</code>.</div>
                </div>
            @endif

            <p class="text-muted mb-3" style="font-size: 12px;">
                The portal is view-only: the member sees their own details, savings, loans and consent, and can ask for a consent change. They cannot edit records or take any financial action.
                @if($member->portal_username)
                    <br>Login name: <code>{{ $member->portal_username }}</code>
                    @if($member->portal_last_login_at) &bull; last sign-in {{ $member->portal_last_login_at->format('d M Y, H:i') }} @endif
                @endif
            </p>

            @if(Auth::user()->isSaccoAdmin())
                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('tenant.members.portal_credentials', $member) }}" class="m-0" onsubmit="return confirm('Issue a new temporary password for this member?');">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-brand-primary">{{ $member->portal_username ? 'Reset password' : 'Enable portal access' }}</button>
                    </form>
                    @if($member->portal_enabled)
                        <form method="POST" action="{{ route('tenant.members.portal_disable', $member) }}" class="m-0" onsubmit="return confirm('Switch off portal access for this member?');">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-danger">Switch off</button>
                        </form>
                    @endif
                </div>
            @else
                <div class="text-muted" style="font-size: 12px;">Only a SACCO admin can change portal access.</div>
            @endif
        </div>

        {{-- Active Savings Ledger Card --}}
        <div class="sacco-card mb-4" style="border-left: 3px solid var(--color-green-start);">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 15px; margin: 0;">Savings</h2>
                    <div style="font-size: 11px; color: var(--color-slate);">Deposits and withdrawals</div>
                </div>
                <a href="{{ route('tenant.members.savings_statement', $member) }}" class="btn btn-sm btn-brand-outline" style="font-size: 11px;">
                    Statement <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="p-3 bg-light rounded text-center border mb-3">
                <div class="stat-title mb-1">Computed Savings Balance</div>
                <div class="fw-bold font-monospace" style="font-size: 22px; color: var(--color-teal);">
                    UGX {{ number_format($member->savings_balance, 2) }}
                </div>
                <div class="text-muted mt-1" style="font-size: 11px;">
                    Deposits: <strong>UGX {{ number_format($member->total_deposits) }}</strong> &bull;
                    Withdrawals: <strong>UGX {{ number_format($member->total_withdrawals) }}</strong>
                </div>
            </div>

            <div class="d-grid mb-3">
                <a href="{{ route('tenant.savings.create', ['member_id' => $member->id]) }}" class="btn btn-brand-primary btn-sm">
                    <i class="bi bi-plus-circle me-1"></i> Record Deposit / Withdrawal
                </a>
            </div>

            {{-- Recent Transactions Mini List --}}
            @php
                $recentTxns = $member->savings()->latest('transaction_date')->latest('id')->take(3)->get();
            @endphp
            @if($recentTxns->isNotEmpty())
                <div class="border-top pt-2">
                    <div class="text-muted mb-2" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Recent Transactions</div>
                    <ul class="list-unstyled mb-0" style="font-size: 12px;">
                        @foreach($recentTxns as $txn)
                            <li class="d-flex justify-content-between align-items-center py-1">
                                <div>
                                    <span class="{{ $txn->isDeposit() ? 'text-success' : 'text-warning' }} fw-bold me-1">
                                        {{ $txn->isDeposit() ? '+' : '−' }}
                                    </span>
                                    <span>{{ $txn->transaction_date->format('d M') }}</span>
                                    <span class="text-muted font-monospace" style="font-size: 10px;">({{ $txn->transaction_reference }})</span>
                                </div>
                                <div class="font-monospace fw-semibold" style="color: {{ $txn->isDeposit() ? 'var(--color-risk-low)' : 'var(--color-risk-high)' }};">
                                    UGX {{ number_format($txn->amount) }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Active Loans & Credit Facility --}}
        <div class="sacco-card mb-4" style="border-left: 3px solid var(--color-teal);">
            <div class="sacco-card-header">
                <div>
                    <h2 style="font-size: 15px; margin: 0;">Loans & Credit Facility</h2>
                    <div style="font-size: 11px; color: var(--color-slate);">Credit History & Debt Service</div>
                </div>
                <a href="{{ route('tenant.loans.create', ['member_id' => $member->id]) }}" class="btn btn-sm btn-brand-primary" style="font-size: 11px;">
                    <i class="bi bi-plus-lg me-1"></i> Apply for Loan
                </a>
            </div>

            <div class="p-3 bg-light rounded text-center border mb-3">
                <div class="stat-title mb-1">Total Outstanding Debt</div>
                <div class="fw-bold font-monospace" style="font-size: 22px; color: {{ $member->total_loan_balance > 0 ? 'var(--color-teal)' : 'var(--color-slate)' }};">
                    UGX {{ number_format($member->total_loan_balance, 2) }}
                </div>
                <div class="text-muted mt-1" style="font-size: 11px;">
                    Completed: <strong>{{ $member->past_loans_count }}</strong> &bull;
                    Defaults: <strong class="{{ $member->past_default_count > 0 ? 'text-danger' : 'text-success' }}">{{ $member->past_default_count }}</strong>
                </div>
            </div>

            @php
                $memberLoans = $member->loans()->take(4)->get();
            @endphp

            @if($memberLoans->isNotEmpty())
                <div class="border-top pt-2">
                    <div class="text-muted mb-2" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Loan Applications & Contracts</div>
                    <ul class="list-unstyled mb-0" style="font-size: 12px;">
                        @foreach($memberLoans as $mLoan)
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <div>
                                    <a href="{{ route('tenant.loans.show', $mLoan) }}" class="fw-bold font-monospace text-decoration-none text-dark">
                                        {{ $mLoan->loan_reference }}
                                    </a>
                                    <div class="text-muted" style="font-size: 11px;">
                                        UGX {{ number_format($mLoan->principal, 0) }} &bull; {{ $mLoan->term_months }} mo
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="badge {{ $mLoan->isDisbursed() ? 'badge-active' : ($mLoan->isDefaulted() ? 'badge-inactive' : 'bg-secondary') }}" style="font-size: 10px;">
                                        {{ strtoupper($mLoan->status) }}
                                    </span>
                                    <div class="text-muted font-monospace" style="font-size: 10px;">
                                        Rem: UGX {{ number_format($mLoan->remaining_balance, 0) }}
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @else
                <div class="text-muted text-center py-2" style="font-size: 12px;">
                    No loan applications on record for this member.
                </div>
            @endif
        </div>

        {{-- Credit risk assessment: latest score, or why there is none --}}
        @php
            $latestScoredLoan = $member->loans()->whereNotNull('risk_score')->first();
        @endphp
        <div class="sacco-card" style="border-left: 3px solid var(--color-slate);">
            <div class="sacco-card-header pb-2">
                <div>
                    <h2 style="font-size: 15px; margin: 0;">Credit Risk Assessment</h2>
                    <div style="font-size: 11px; color: var(--color-slate);">Based on savings and repayment history</div>
                </div>
            </div>
            @if($member->hasDeclinedScoring())
                <p class="text-muted mb-0" style="font-size: 12px;">
                    This member has declined credit scoring, so no risk score is calculated. Loan applications are decided by an officer.
                </p>
            @elseif(! $member->canBeScored())
                <p class="text-muted mb-0" style="font-size: 12px;">
                    No consent has been recorded, so this member is not credit scored. Record consent above to allow scoring.
                </p>
            @elseif($latestScoredLoan)
                <div class="d-flex align-items-baseline gap-2">
                    <span class="fw-bold font-monospace" style="font-size: 24px;">{{ number_format($latestScoredLoan->risk_score, 1) }}</span>
                    <span class="text-muted" style="font-size: 12px;">out of 100 (higher means higher risk)</span>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size: 12px;">
                    From loan application
                    <a href="{{ route('tenant.loans.show', $latestScoredLoan) }}" class="text-dark">{{ $latestScoredLoan->loan_reference }}</a>
                    ({{ $latestScoredLoan->applied_at->format('d M Y') }}).
                    @if($latestScoredLoan->decision)
                        Recommendation: <strong>{{ \App\Services\LendingDecisionEngine::label($latestScoredLoan->decision) }}</strong>.
                    @endif
                </p>
            @else
                <p class="text-muted mb-0" style="font-size: 12px;">
                    A credit risk assessment will appear here once this member applies for a loan.
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
