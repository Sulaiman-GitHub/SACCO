@extends('layouts.app')

@section('title', 'Apply for Loan')
@section('page_title', 'New Loan Application')

@section('topbar_actions')
    <a href="{{ route('tenant.loans.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Loans
    </a>
@endsection

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <div>
                    <h5 class="mb-1">Loan Application Details</h5>
                    <div class="text-muted" style="font-size: 13px;">Capture applicant loan request, interest terms, and guarantor information.</div>
                </div>
                <div class="text-end font-monospace text-muted" style="font-size: 12px;">
                    Next Ref: <strong class="text-dark">{{ $nextReference }}</strong>
                </div>
            </div>

            <form method="POST" action="{{ route('tenant.loans.store') }}" id="loanForm">
                @csrf

                {{-- Borrower Selection --}}
                <div class="mb-4">
                    <label for="member_id" class="form-label fw-bold" style="font-size: 13px;">
                        Select Active Member <span class="text-danger">*</span>
                    </label>
                    <select name="member_id" id="member_id" class="form-select @error('member_id') is-invalid @enderror" required>
                        <option value="">-- Choose Member --</option>
                        @foreach($members as $m)
                            <option value="{{ $m->id }}"
                                    data-savings="{{ $m->savings_balance }}"
                                    data-phone="{{ $m->phone }}"
                                    data-no="{{ $m->member_number }}"
                                    {{ (old('member_id', $selectedMember?->id) == $m->id) ? 'selected' : '' }}>
                                {{ $m->full_name }} ({{ $m->member_number }}) — Current Savings: UGX {{ number_format($m->savings_balance, 0) }}
                            </option>
                        @endforeach
                    </select>
                    @error('member_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Financial Terms Grid --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="principal" class="form-label fw-bold" style="font-size: 13px;">
                            Principal Requested (UGX) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light font-monospace">UGX</span>
                            <input type="number" step="any" name="principal" id="principal"
                                   class="form-control @error('principal') is-invalid @enderror"
                                   placeholder="e.g. 1500000"
                                   value="{{ old('principal', '1000000') }}" required>
                        </div>
                        @error('principal')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label for="interest_rate" class="form-label fw-bold" style="font-size: 13px;">
                            Interest Rate (% p.a.) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" step="any" name="interest_rate" id="interest_rate"
                                   class="form-control @error('interest_rate') is-invalid @enderror"
                                   value="{{ old('interest_rate', '10.0') }}" required>
                            <span class="input-group-text bg-light">%</span>
                        </div>
                        @error('interest_rate')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label for="term_months" class="form-label fw-bold" style="font-size: 13px;">
                            Term (Months) <span class="text-danger">*</span>
                        </label>
                        <select name="term_months" id="term_months" class="form-select @error('term_months') is-invalid @enderror" required>
                            @foreach([1, 2, 3, 6, 9, 12, 18, 24, 36] as $m)
                                <option value="{{ $m }}" {{ old('term_months', 6) == $m ? 'selected' : '' }}>
                                    {{ $m }} {{ $m === 1 ? 'Month' : 'Months' }}
                                </option>
                            @endforeach
                        </select>
                        @error('term_months')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="applied_at" class="form-label fw-bold" style="font-size: 13px;">
                            Application Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="applied_at" id="applied_at" max="{{ date('Y-m-d') }}"
                               class="form-control @error('applied_at') is-invalid @enderror"
                               value="{{ old('applied_at', date('Y-m-d')) }}" required>
                        @error('applied_at')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="interest_calculation_type" class="form-label fw-bold" style="font-size: 13px;">
                            Calculation Type <span class="text-danger">*</span>
                        </label>
                        <select name="interest_calculation_type" id="interest_calculation_type" class="form-select" required>
                            <option value="flat" {{ old('interest_calculation_type') === 'flat' ? 'selected' : '' }}>Flat Rate (Standard SACCO)</option>
                            <option value="simple" {{ old('interest_calculation_type') === 'simple' ? 'selected' : '' }}>Simple Interest</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="purpose" class="form-label fw-bold" style="font-size: 13px;">Loan Purpose / Sector</label>
                    <input type="text" name="purpose" id="purpose" class="form-control @error('purpose') is-invalid @enderror"
                           placeholder="e.g. Agricultural inputs, School tuition fees, Business working capital"
                           value="{{ old('purpose') }}">
                    @error('purpose')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Guarantor Section --}}
                <div class="p-3 bg-light rounded border mb-4">
                    <div class="fw-bold mb-3 text-dark" style="font-size: 14px;">
                        <i class="bi bi-shield-check me-1 text-success"></i> Guarantor Information (Optional / Risk Mitigation)
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="guarantor_name" class="form-label" style="font-size: 12px;">Full Name</label>
                            <input type="text" name="guarantor_name" id="guarantor_name" class="form-control form-control-sm"
                                   placeholder="Guarantor name" value="{{ old('guarantor_name') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="guarantor_phone" class="form-label" style="font-size: 12px;">Phone Number</label>
                            <input type="text" name="guarantor_phone" id="guarantor_phone" class="form-control form-control-sm"
                                   placeholder="+256 700 000000" value="{{ old('guarantor_phone') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="guarantor_relationship" class="form-label" style="font-size: 12px;">Relationship</label>
                            <input type="text" name="guarantor_relationship" id="guarantor_relationship" class="form-control form-control-sm"
                                   placeholder="Spouse / Business Partner" value="{{ old('guarantor_relationship') }}">
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label" style="font-size: 13px;">Loan Officer Assessment Notes</label>
                    <textarea name="notes" id="notes" rows="2" class="form-control"
                              placeholder="Any collateral pledge, site visit remarks, or special considerations...">{{ old('notes') }}</textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('tenant.loans.index') }}" class="btn btn-brand-outline">Cancel</a>
                    <button type="submit" class="btn btn-brand-primary">
                        <i class="bi bi-check2-circle me-1"></i> Submit Application for Review
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Live Underwriting & Terms Preview Box --}}
    <div class="col-lg-4">
        <div class="sacco-card sticky-top" style="top: 20px;">
            <div class="sacco-card-header pb-2">
                <h6 class="mb-0 fw-bold">Loan Summary</h6>
            </div>

            {{-- Member Underwriting Metrics --}}
            <div class="mb-3 p-3 rounded border" style="background: #F8F9FA;">
                <div class="text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Applicant Savings</div>
                <div class="fs-5 fw-bold font-monospace" id="previewSavings" style="color: var(--color-teal);">
                    UGX 0
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top" style="font-size: 12px;">
                    <span class="text-muted">Loan-to-Savings:</span>
                    <strong id="previewRatio" class="font-monospace">0.0x</strong>
                </div>
                <div id="ratioWarning" class="alert alert-warning p-2 mt-2 mb-0 d-none" style="font-size: 11px;">
                    <i class="bi bi-exclamation-triangle me-1"></i> Loan exceeds 3x member savings. Guarantor recommended.
                </div>
            </div>

            {{-- Loan Repayment Calculations --}}
            <div class="list-group list-group-flush mb-3" style="font-size: 13px;">
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Principal:</span>
                    <span class="fw-semibold font-monospace" id="previewPrincipal">UGX 1,000,000</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Estimated Interest:</span>
                    <span class="fw-semibold font-monospace" id="previewInterest" style="color: var(--color-teal);">UGX 50,000</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="fw-bold">Total Amount Payable:</span>
                    <span class="fw-bold font-monospace fs-6" id="previewTotal" style="color: var(--color-black);">UGX 1,050,000</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Monthly Installment:</span>
                    <span class="fw-bold font-monospace" id="previewMonthly" style="color: var(--color-green-start);">UGX 175,000 / mo</span>
                </div>
            </div>

            <div class="p-2 bg-light rounded text-muted" style="font-size: 11px; line-height: 1.4;">
                <i class="bi bi-info-circle me-1"></i> Loans require formal approval and disbursement recording before active repayment tracking commences.
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const memberSelect = document.getElementById('member_id');
    const principalInput = document.getElementById('principal');
    const rateInput = document.getElementById('interest_rate');
    const termSelect = document.getElementById('term_months');

    const previewSavings = document.getElementById('previewSavings');
    const previewRatio = document.getElementById('previewRatio');
    const ratioWarning = document.getElementById('ratioWarning');
    const previewPrincipal = document.getElementById('previewPrincipal');
    const previewInterest = document.getElementById('previewInterest');
    const previewTotal = document.getElementById('previewTotal');
    const previewMonthly = document.getElementById('previewMonthly');

    function updatePreview() {
        const principal = parseFloat(principalInput.value) || 0;
        const rate = parseFloat(rateInput.value) || 0;
        const term = parseInt(termSelect.value) || 1;

        // Interest = Principal * (Rate / 100) * (Term / 12)
        const interest = Math.round(principal * (rate / 100) * (term / 12));
        const total = principal + interest;
        const monthly = term > 0 ? Math.round(total / term) : total;

        previewPrincipal.textContent = 'UGX ' + principal.toLocaleString();
        previewInterest.textContent = 'UGX ' + interest.toLocaleString();
        previewTotal.textContent = 'UGX ' + total.toLocaleString();
        previewMonthly.textContent = 'UGX ' + monthly.toLocaleString() + ' / mo';

        // Member savings
        const selectedOption = memberSelect.options[memberSelect.selectedIndex];
        const savings = selectedOption ? (parseFloat(selectedOption.getAttribute('data-savings')) || 0) : 0;
        previewSavings.textContent = 'UGX ' + savings.toLocaleString();

        if (savings > 0 && principal > 0) {
            const ratio = (principal / savings).toFixed(2);
            previewRatio.textContent = ratio + 'x';
            if (ratio > 3.0) {
                ratioWarning.classList.remove('d-none');
            } else {
                ratioWarning.classList.add('d-none');
            }
        } else {
            previewRatio.textContent = savings === 0 && principal > 0 ? 'No Savings' : '0.0x';
            ratioWarning.classList.add('d-none');
        }
    }

    memberSelect.addEventListener('change', updatePreview);
    principalInput.addEventListener('input', updatePreview);
    rateInput.addEventListener('input', updatePreview);
    termSelect.addEventListener('change', updatePreview);

    updatePreview();
});
</script>
@endsection
