@extends('layouts.app')

@section('title', 'Loan ' . $loan->loan_reference)
@section('page_title', 'Loan Dossier: ' . $loan->loan_reference)

@section('topbar_actions')
    <a href="{{ route('tenant.loans.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Loans List
    </a>

    @if($loan->canBeApproved())
        <button type="button" class="btn btn-brand-primary" data-bs-toggle="modal" data-bs-target="#approveModal">
            <i class="bi bi-check-circle me-1"></i> Approve Loan
        </button>
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
            <i class="bi bi-x-circle me-1"></i> Reject
        </button>
    @elseif($loan->canBeDisbursed())
        <button type="button" class="btn btn-brand-primary" data-bs-toggle="modal" data-bs-target="#disburseModal">
            <i class="bi bi-cash-stack me-1"></i> Disburse Funds
        </button>
    @elseif($loan->canAcceptRepayment())
        <a href="{{ route('tenant.repayments.create', ['loan_id' => $loan->id]) }}" class="btn btn-brand-primary">
            <i class="bi bi-receipt me-1"></i> Record Repayment
        </a>
    @endif
@endsection

@section('content')
{{-- Delinquency & Status Alerts --}}
@if($loan->isDefaulted())
    <div class="alert alert-danger d-flex align-items-center mb-4 p-3" role="alert" style="border-radius: 4px; border-left: 5px solid var(--color-risk-high);">
        <i class="bi bi-exclamation-triangle-fill fs-3 me-3 text-danger"></i>
        <div>
            <div class="fw-bold fs-6">DEFAULTED LOAN ALERT (&gt;90 DAYS OVERDUE)</div>
            <div style="font-size: 13px;">This loan exceeded its maturity due date of <strong>{{ $loan->due_date ? $loan->due_date->format('d M Y') : 'N/A' }}</strong> by <strong>{{ $loan->days_overdue }} days</strong>. Legal recovery or guarantor contact required.</div>
        </div>
    </div>
@elseif($loan->isOverdue())
    <div class="alert alert-warning d-flex align-items-center mb-4 p-3" role="alert" style="border-radius: 4px; border-left: 5px solid var(--color-risk-medium);">
        <i class="bi bi-clock-history fs-3 me-3 text-warning"></i>
        <div>
            <div class="fw-bold fs-6">OVERDUE LOAN ALERT (WITHIN 90 DAYS GRACE)</div>
            <div style="font-size: 13px;">This loan matured on <strong>{{ $loan->due_date ? $loan->due_date->format('d M Y') : 'N/A' }}</strong> and is currently <strong>{{ $loan->days_overdue }} days past due</strong>.</div>
        </div>
    </div>
@elseif($loan->isClosed())
    <div class="alert alert-success d-flex align-items-center mb-4 p-3" role="alert" style="border-radius: 4px; border-left: 5px solid var(--color-risk-low);">
        <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
        <div>
            <div class="fw-bold fs-6">LOAN FULLY REPAID & CLOSED</div>
            <div style="font-size: 13px;">All obligations have been discharged. Outstanding balance is UGX 0.00.</div>
        </div>
    </div>
@elseif($loan->isRejected())
    <div class="alert alert-danger mb-4 p-3" role="alert" style="border-radius: 4px;">
        <div class="fw-bold"><i class="bi bi-x-octagon-fill me-1"></i> Loan Application Rejected</div>
        <div class="mt-1" style="font-size: 13px;">Reason: <em>{{ $loan->rejection_reason }}</em></div>
    </div>
@endif

{{-- Summary Financial Tiles --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Principal Amount</div>
            <div class="stat-value">UGX {{ number_format($loan->principal, 0) }}</div>
            <div class="stat-desc">+UGX {{ number_format($loan->total_interest, 0) }} ({{ $loan->interest_rate }}% flat)</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Total Payable</div>
            <div class="stat-value">UGX {{ number_format($loan->total_amount, 0) }}</div>
            <div class="stat-desc">Term: {{ $loan->term_months }} months</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Total Repaid</div>
            <div class="stat-value" style="color: var(--color-green-start);">UGX {{ number_format($loan->total_repaid, 0) }}</div>
            <div class="stat-desc">{{ $loan->repayments->count() }} installment payment(s)</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-tile">
            <div class="stat-title">Remaining Balance</div>
            <div class="stat-value" style="color: {{ $loan->remaining_balance > 0 ? 'var(--color-teal)' : 'var(--color-slate)' }};">
                UGX {{ number_format($loan->remaining_balance, 0) }}
            </div>
            <div class="stat-desc">{{ $loan->repayment_percentage }}% recovered</div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Main Column: Borrower, Terms, Repayment Ledger --}}
    <div class="col-lg-8">
        {{-- Not scored: member declined, or no consent record (consent) --}}
        @if($member && ! $member->canBeScored())
        <div class="sacco-card mb-4" style="border-left: 4px solid var(--color-slate);">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-person-lock fs-5"></i>
                <h6 class="mb-0 fw-bold">{{ $member->hasDeclinedScoring() ? 'Credit scoring declined by member' : 'No consent record for credit scoring' }}</h6>
            </div>
            <p class="mb-0" style="font-size: 13px;">
                @if($member->hasDeclinedScoring())
                    This member did not consent to credit scoring, so no risk score or recommendation is produced. The approving officer decides on the application manually.
                @else
                    No consent has been recorded for this member, and a member is never scored without it. Record consent on the member profile, or the approving officer decides on the application manually.
                @endif
            </p>
        </div>
        @endif

        {{-- Rule Engine Decision Card --}}
        @if($loan->decision)
        @php
            $decisionColor = match ($loan->decision) {
                'approve' => 'var(--color-risk-low)',
                'require_guarantor' => 'var(--color-risk-medium)',
                default => 'var(--color-risk-high)',
            };
        @endphp
        <div class="sacco-card mb-4" style="border-left: 4px solid {{ $decisionColor }};">
            <div class="sacco-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-clipboard-check fs-5" style="color: {{ $decisionColor }};"></i>
                    <h6 class="mb-0 fw-bold">Lending Policy Recommendation</h6>
                    <span class="badge" style="background-color: {{ $decisionColor }}; color: #fff; font-size: 11px;">
                        {{ \App\Services\LendingDecisionEngine::label($loan->decision) }}
                    </span>
                </div>
                <a href="{{ Auth::user()->isSaccoAdmin() ? route('tenant.lending_policy.edit') : '#' }}" class="text-muted" style="font-size: 12px;">Policy thresholds</a>
            </div>
            <p class="mb-1" style="font-size: 13px;">{{ $loan->decision_reason }}</p>
            <div class="text-muted" style="font-size: 12px;">This is a recommendation. The final decision rests with the approving officer, and overriding it requires a recorded reason.</div>
        </div>
        @endif

        {{-- ML Credit Risk Scoring Card --}}
        @if(isset($riskAssessment))
        <div class="sacco-card mb-4" style="border-left: 4px solid {{ $riskAssessment['risk_band'] === 'low' ? 'var(--color-risk-low)' : ($riskAssessment['risk_band'] === 'medium' ? 'var(--color-risk-medium)' : 'var(--color-risk-high)') }};">
            <div class="sacco-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-cpu fs-5" style="color: var(--color-teal);"></i>
                    <h6 class="mb-0 fw-bold">Credit Risk Assessment</h6>
                    <span class="badge" style="background-color: {{ $riskAssessment['risk_band'] === 'low' ? 'var(--color-risk-low)' : ($riskAssessment['risk_band'] === 'medium' ? 'var(--color-risk-medium)' : 'var(--color-risk-high)') }}; color: #fff; font-size: 11px;">
                        {{ strtoupper($riskAssessment['risk_band']) }} RISK
                    </span>
                </div>
                @if(!$riskAssessment['success'])
                    <div style="font-size: 12px;" class="text-muted">
                        <span class="badge bg-warning text-dark">Simplified estimate</span>
                        The credit scoring service was unavailable.
                    </div>
                @endif
            </div>

            <div class="row g-3 align-items-center">
                <div class="col-md-4 text-center py-2 border-end-md">
                    <div class="text-muted text-uppercase" style="font-size: 11px; letter-spacing: 0.5px;">Credit Risk Score</div>
                    <div style="font-size: 38px; font-weight: 800; line-height: 1.1; color: {{ $riskAssessment['risk_band'] === 'low' ? 'var(--color-risk-low)' : ($riskAssessment['risk_band'] === 'medium' ? 'var(--color-risk-medium)' : 'var(--color-risk-high)') }};">
                        {{ number_format($riskAssessment['risk_score'], 1) }}
                    </div>
                    <div class="text-muted" style="font-size: 12px;">Score from 0 (lowest risk) to 100 (highest risk). A guide for the officer, not a prediction.</div>
                    <div class="mt-1" style="font-size: 13px;">
                        @if($riskAssessment['probability_calibrated'] ?? false)
                            Estimated chance of default: <strong>{{ number_format($riskAssessment['default_probability'] * 100, 1) }}%</strong>
                            <div class="text-muted" style="font-size: 11px;">Based on sample data rather than real SACCO history, so treat it as a rough guide.</div>
                        @else
                            <span class="text-muted">No default estimate is available. Use the score only to compare applicants.</span>
                        @endif
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="fw-bold mb-2" style="font-size: 13px;">What this score is based on</div>
                    <div class="row g-2" style="font-size: 12px;">
                        <div class="col-6">
                            <span class="text-muted">Deposit Frequency:</span>
                            <span class="fw-semibold font-monospace float-end">{{ $riskAssessment['features']['contribution_frequency'] }} /mo</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Deposit Consistency:</span>
                            <span class="fw-semibold font-monospace float-end">{{ $riskAssessment['features']['contribution_consistency'] }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Savings Cushion:</span>
                            <span class="fw-semibold font-monospace float-end">UGX {{ number_format($riskAssessment['features']['savings_balance'], 0) }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Member Tenure:</span>
                            <span class="fw-semibold font-monospace float-end">{{ $riskAssessment['features']['tenure_months'] }} mos</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Past Completed Loans:</span>
                            <span class="fw-semibold font-monospace float-end">{{ $riskAssessment['features']['past_loans_count'] }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Past Default Events:</span>
                            <span class="fw-semibold font-monospace float-end {{ $riskAssessment['features']['past_default_count'] > 0 ? 'text-danger fw-bold' : '' }}">{{ $riskAssessment['features']['past_default_count'] }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">On-Time Repayment:</span>
                            <span class="fw-semibold font-monospace float-end">{{ number_format($riskAssessment['features']['on_time_repayment_rate'] * 100, 0) }}%</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Loan / Savings Ratio:</span>
                            <span class="fw-semibold font-monospace float-end">{{ $riskAssessment['features']['loan_to_savings_ratio'] }}x</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Borrower Profile Card --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-badge fs-5 text-muted"></i>
                    <h6 class="mb-0 fw-bold">Borrower Profile & Underwriting Baseline</h6>
                </div>
                <div>
                    <a href="{{ route('tenant.members.show', $loan->member) }}" class="btn btn-sm btn-brand-outline">
                        View Full Member File <i class="bi bi-box-arrow-up-right ms-1"></i>
                    </a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0" style="font-size: 13px;">
                        <tr>
                            <td class="text-muted" style="width: 140px;">Borrower Name:</td>
                            <td class="fw-bold text-dark">{{ $loan->member->full_name }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Member ID:</td>
                            <td><code class="fw-bold">{{ $loan->member->member_number }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Phone Contact:</td>
                            <td class="fw-semibold">{{ $loan->member->phone }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">National ID (Masked):</td>
                            <td class="font-monospace text-muted">{{ $loan->member->national_id_masked ?? '—' }}</td>
                        </tr>
                    </table>
                </div>

                <div class="col-md-6 border-start-md">
                    <table class="table table-sm table-borderless mb-0" style="font-size: 13px;">
                        <tr>
                            <td class="text-muted" style="width: 160px;">Savings Balance:</td>
                            <td class="fw-bold font-monospace" style="color: var(--color-teal);">
                                UGX {{ number_format($savingsBalance, 2) }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Loan-to-Savings Ratio:</td>
                            <td class="fw-bold font-monospace">
                                {{ $loanToSavingsRatio }}x
                                @if($loanToSavingsRatio > 3.0)
                                    <span class="badge bg-warning text-dark ms-1" style="font-size: 10px;">&gt;3x Threshold</span>
                                @else
                                    <span class="badge bg-success text-white ms-1" style="font-size: 10px;">Within 3x limit</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Past Completed Loans:</td>
                            <td>{{ $loan->member->past_loans_count }} loan(s)</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Past Defaults:</td>
                            <td>
                                @if($loan->member->past_default_count > 0)
                                    <span class="badge bg-danger">{{ $loan->member->past_default_count }} past default(s)</span>
                                @else
                                    <span class="text-success"><i class="bi bi-shield-check"></i> Clean history (0)</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Repayment Progress Card --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header">
                <div>
                    <h6 class="mb-0 fw-bold">Repayment Progress</h6>
                    <div class="text-muted" style="font-size: 12px;">How much of the loan has been repaid</div>
                </div>
                <div class="font-monospace fw-bold" style="font-size: 14px;">
                    {{ $loan->repayment_percentage }}% Recovered
                </div>
            </div>

            <div class="progress mb-3" style="height: 12px; background-color: #E8ECEF; border-radius: 3px;">
                <div class="progress-bar" role="progressbar"
                     style="width: {{ $loan->repayment_percentage }}%; background: linear-gradient(90deg, var(--color-green-start) 0%, var(--color-green-end) 100%);"
                     aria-valuenow="{{ $loan->repayment_percentage }}" aria-valuemin="0" aria-valuemax="100">
                </div>
            </div>

            <div class="d-flex justify-content-between text-muted" style="font-size: 12px;">
                <span>Disbursed: UGX {{ number_format($loan->total_amount, 2) }}</span>
                <span>Repaid: UGX {{ number_format($loan->total_repaid, 2) }}</span>
                <span class="fw-bold text-dark">Unpaid: UGX {{ number_format($loan->remaining_balance, 2) }}</span>
            </div>
        </div>

        {{-- Append-Only Repayments Ledger Table --}}
        <div class="sacco-card p-0">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-0 fw-bold">Repayment History</h6>
                    <div class="text-muted" style="font-size: 12px;">Payments are permanent records and cannot be edited or deleted</div>
                </div>
                @if($loan->canAcceptRepayment())
                    <a href="{{ route('tenant.repayments.create', ['loan_id' => $loan->id]) }}" class="btn btn-sm btn-brand-primary">
                        <i class="bi bi-plus-circle me-1"></i> Record Repayment
                    </a>
                @endif
            </div>

            <div class="table-responsive">
                <table class="sacco-table">
                    <thead>
                        <tr>
                            <th>Repayment Ref</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Payment Channel</th>
                            <th>Recorded By</th>
                            <th class="text-end">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loan->repayments as $rep)
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold text-dark">{{ $rep->repayment_reference }}</span>
                                </td>
                                <td>{{ $rep->repayment_date->format('d M Y') }}</td>
                                <td class="font-monospace fw-bold text-success">
                                    UGX {{ number_format($rep->amount, 2) }}
                                </td>
                                <td>
                                    <div style="font-size: 12px;">{{ strtoupper(str_replace('_', ' ', $rep->payment_method)) }}</div>
                                    @if($rep->payment_reference)
                                        <div class="text-muted font-monospace" style="font-size: 11px;">Ref: {{ $rep->payment_reference }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-muted" style="font-size: 12px;">{{ $rep->recorder->name ?? 'System' }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('tenant.repayments.show', $rep) }}" class="btn btn-sm btn-brand-outline py-0 px-2" title="Official Receipt">
                                        <i class="bi bi-printer me-1"></i> Receipt
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    <i class="bi bi-journal-x fs-3 d-block mb-1"></i>
                                    No repayment installments recorded for this loan yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Sidebar Column: Terms, Guarantor, Audit Trail --}}
    <div class="col-lg-4">
        {{-- Loan Terms Card --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header pb-2">
                <h6 class="mb-0 fw-bold">Contract Terms & Schedule</h6>
            </div>
            <table class="table table-sm table-borderless mb-0" style="font-size: 13px;">
                <tr>
                    <td class="text-muted">Status:</td>
                    <td class="text-end">
                        <span class="badge {{ $loan->isDisbursed() ? 'badge-active' : ($loan->isDefaulted() ? 'badge-inactive' : 'bg-secondary') }}">
                            {{ strtoupper($loan->status) }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td class="text-muted">Interest Rate:</td>
                    <td class="text-end fw-semibold">{{ $loan->interest_rate }}% per annum</td>
                </tr>
                <tr>
                    <td class="text-muted">Calculation Method:</td>
                    <td class="text-end fw-semibold">{{ ucfirst($loan->interest_calculation_type) }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Term Length:</td>
                    <td class="text-end fw-semibold">{{ $loan->term_months }} Months</td>
                </tr>
                <tr>
                    <td class="text-muted">Application Date:</td>
                    <td class="text-end">{{ $loan->applied_at ? $loan->applied_at->format('d M Y') : '—' }}</td>
                </tr>
                <tr>
                    <td class="text-muted">Maturity Due Date:</td>
                    <td class="text-end fw-bold {{ $loan->is_overdue ? 'text-danger' : 'text-dark' }}">
                        {{ $loan->due_date ? $loan->due_date->format('d M Y') : 'Pending Disbursement' }}
                    </td>
                </tr>
                @if($loan->purpose)
                    <tr>
                        <td class="text-muted">Purpose:</td>
                        <td class="text-end fst-italic">{{ $loan->purpose }}</td>
                    </tr>
                @endif
            </table>
        </div>

        {{-- Guarantor Card --}}
        <div class="sacco-card mb-4">
            <div class="sacco-card-header pb-2">
                <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-1 text-success"></i> Guarantor Information</h6>
            </div>
            @if($loan->guarantor_name)
                <table class="table table-sm table-borderless mb-0" style="font-size: 13px;">
                    <tr>
                        <td class="text-muted">Full Name:</td>
                        <td class="text-end fw-bold">{{ $loan->guarantor_name }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Phone Number:</td>
                        <td class="text-end fw-semibold">{{ $loan->guarantor_phone ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Relationship:</td>
                        <td class="text-end text-muted">{{ $loan->guarantor_relationship ?? '—' }}</td>
                    </tr>
                </table>
            @else
                <div class="text-muted fst-italic text-center py-2" style="font-size: 13px;">
                    No guarantor attached to this loan.
                </div>
            @endif
        </div>

        {{-- Audit Trail Card --}}
        <div class="sacco-card">
            <div class="sacco-card-header pb-2">
                <h6 class="mb-0 fw-bold">Loan History</h6>
            </div>
            <div class="timeline" style="font-size: 12px;">
                <div class="mb-3">
                    <div class="fw-bold text-dark">1. Application Created</div>
                    <div class="text-muted">By: {{ $loan->creator->name ?? 'Staff' }}</div>
                    <div class="text-muted">{{ $loan->created_at->format('d M Y, H:i') }}</div>
                </div>

                @if($loan->approved_at)
                    <div class="mb-3">
                        <div class="fw-bold text-success">2. Application Approved</div>
                        <div class="text-muted">By: {{ $loan->approver->name ?? 'Staff' }}</div>
                        <div class="text-muted">{{ $loan->approved_at->format('d M Y, H:i') }}</div>
                    </div>
                @endif

                @if($loan->rejected_at)
                    <div class="mb-3">
                        <div class="fw-bold text-danger">Application Rejected</div>
                        <div class="text-muted">By: {{ $loan->rejecter->name ?? 'Staff' }}</div>
                        <div class="text-muted">{{ $loan->rejected_at->format('d M Y, H:i') }}</div>
                        <div class="text-danger mt-1">"{{ $loan->rejection_reason }}"</div>
                    </div>
                @endif

                @if($loan->disbursed_at)
                    <div class="mb-3">
                        <div class="fw-bold text-primary">3. Funds Disbursed</div>
                        <div class="text-muted">By: {{ $loan->disburser->name ?? 'Staff' }}</div>
                        <div class="text-muted">Method: {{ strtoupper($loan->disbursement_method) }} ({{ $loan->disbursement_reference ?? 'N/A' }})</div>
                        <div class="text-muted">{{ $loan->disbursed_at->format('d M Y, H:i') }}</div>
                    </div>
                @endif

                @if($loan->isClosed())
                    <div class="mb-2">
                        <div class="fw-bold text-success">4. Obligation Discharged</div>
                        <div class="text-muted">Loan closed upon full principal and interest recovery.</div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Modal: Approve Loan --}}
<div class="modal fade" id="approveModal" tabindex="-1" aria-labelledby="approveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('tenant.loans.approve', $loan) }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="approveModalLabel">Approve Loan Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to approve loan <strong>{{ $loan->loan_reference }}</strong> for <strong>{{ $loan->member->full_name }}</strong>?</p>
                <div class="p-3 bg-light rounded mb-3" style="font-size: 13px;">
                    <div>Principal: <strong>UGX {{ number_format($loan->principal, 2) }}</strong></div>
                    <div>Total Amount: <strong>UGX {{ number_format($loan->total_amount, 2) }}</strong></div>
                    <div>Term: <strong>{{ $loan->term_months }} Months</strong></div>
                </div>
                <div class="mb-3">
                    @if($loan->isApprovalOverride())
                        <div class="alert alert-warning py-2" style="font-size: 12px;">
                            The lending policy did not recommend approval ({{ \App\Services\LendingDecisionEngine::label($loan->decision) }}). Record why you are overriding it.
                        </div>
                    @endif
                    <label for="approveNotes" class="form-label" style="font-size: 13px;">Approval Remarks {{ $loan->isApprovalOverride() ? '(Required)' : '(Optional)' }}</label>
                    <textarea name="notes" id="approveNotes" class="form-control" rows="2" placeholder="Approval committee remarks..." @required($loan->isApprovalOverride())></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary">Confirm Approval</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Reject Loan --}}
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('tenant.loans.reject', $loan) }}" class="modal-content">
            @csrf
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="rejectModalLabel">Reject Loan Application</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Please state the reason for rejecting loan application <strong>{{ $loan->loan_reference }}</strong>.</p>
                <div class="mb-3">
                    <label for="rejection_reason" class="form-label fw-bold" style="font-size: 13px;">
                        Rejection Reason <span class="text-danger">*</span>
                    </label>
                    <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3"
                              placeholder="e.g. Insufficient savings balance, poor historical repayment track record, or unverified guarantor." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject Application</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal: Disburse Loan --}}
<div class="modal fade" id="disburseModal" tabindex="-1" aria-labelledby="disburseModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('tenant.loans.disburse', $loan) }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="disburseModalLabel">Record Loan Disbursement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2" style="font-size: 13px;">
                    Disbursement establishes the contract maturity date (<strong>{{ $loan->term_months }} months</strong> from disbursement date) and starts repayment tracking.
                </div>

                <div class="mb-3">
                    <label for="disbursement_method" class="form-label fw-bold" style="font-size: 13px;">
                        Disbursement Method <span class="text-danger">*</span>
                    </label>
                    <select name="disbursement_method" id="disbursement_method" class="form-select" required>
                        <option value="cash">Cash Handover</option>
                        <option value="mtn_momo">MTN Mobile Money</option>
                        <option value="airtel_money">Airtel Money</option>
                        <option value="bank_transfer">Bank Transfer / Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="disbursement_reference" class="form-label fw-bold" style="font-size: 13px;">
                        Payment Reference / Txn ID
                    </label>
                    <input type="text" name="disbursement_reference" id="disbursement_reference" class="form-control"
                           placeholder="e.g. MM82947192, Bank Cheque #0042">
                </div>

                <div class="mb-3">
                    <label for="disbursement_date" class="form-label fw-bold" style="font-size: 13px;">
                        Disbursement Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="disbursement_date" id="disbursement_date" class="form-control"
                           value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}" required>
                </div>

                <div class="mb-3">
                    <label for="disburse_notes" class="form-label" style="font-size: 13px;">Disbursement Remarks</label>
                    <textarea name="notes" id="disburse_notes" class="form-control" rows="2" placeholder="Cashier or bank voucher notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand-primary">Confirm Disbursement</button>
            </div>
        </form>
    </div>
</div>
@endsection
