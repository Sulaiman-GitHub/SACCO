@extends('layouts.app')

@section('title', 'Record Loan Repayment')
@section('page_title', 'Record Loan Repayment')

@section('topbar_actions')
    <a href="{{ route('tenant.repayments.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Repayments
    </a>
@endsection

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <div>
                    <h5 class="mb-1">Installment Payment Entry</h5>
                    <div class="text-muted" style="font-size: 13px;">Record a repayment against an active loan.</div>
                </div>
                <div class="font-monospace text-muted" style="font-size: 12px;">
                    Next Ref: <strong class="text-dark">{{ $nextReference }}</strong>
                </div>
            </div>

            <form method="POST" action="{{ route('tenant.repayments.store') }}" id="repaymentForm">
                @csrf

                {{-- Loan Selection --}}
                <div class="mb-4">
                    <label for="loan_id" class="form-label fw-bold" style="font-size: 13px;">
                        Select Active Loan <span class="text-danger">*</span>
                    </label>
                    <select name="loan_id" id="loan_id" class="form-select @error('loan_id') is-invalid @enderror" required>
                        <option value="">-- Choose Loan to Repay --</option>
                        @foreach($loans as $loan)
                            <option value="{{ $loan->id }}"
                                    data-balance="{{ $loan->remaining_balance }}"
                                    data-total="{{ $loan->total_amount }}"
                                    data-member="{{ $loan->member->full_name }}"
                                    data-ref="{{ $loan->loan_reference }}"
                                    {{ (old('loan_id', $selectedLoan?->id) == $loan->id) ? 'selected' : '' }}>
                                {{ $loan->loan_reference }} — {{ $loan->member->full_name }} (Rem: UGX {{ number_format($loan->remaining_balance, 0) }} / Total: UGX {{ number_format($loan->total_amount, 0) }})
                            </option>
                        @endforeach
                    </select>
                    @error('loan_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Payment Amount & Date --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="amount" class="form-label fw-bold" style="font-size: 13px;">
                            Repayment Amount (UGX) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light font-monospace">UGX</span>
                            <input type="number" step="1000" min="1000" name="amount" id="amount"
                                   class="form-control @error('amount') is-invalid @enderror"
                                   placeholder="e.g. 250000"
                                   value="{{ old('amount') }}" required>
                        </div>
                        @error('amount')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="repayment_date" class="form-label fw-bold" style="font-size: 13px;">
                            Payment Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="repayment_date" id="repayment_date" max="{{ date('Y-m-d') }}"
                               class="form-control @error('repayment_date') is-invalid @enderror"
                               value="{{ old('repayment_date', date('Y-m-d')) }}" required>
                        @error('repayment_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Payment Channel & Reference --}}
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label fw-bold" style="font-size: 13px;">
                            Payment Channel <span class="text-danger">*</span>
                        </label>
                        <select name="payment_method" id="payment_method" class="form-select @error('payment_method') is-invalid @enderror" required>
                            <option value="cash" {{ old('payment_method') === 'cash' ? 'selected' : '' }}>Cash Handover</option>
                            <option value="mtn_momo" {{ old('payment_method') === 'mtn_momo' ? 'selected' : '' }}>MTN Mobile Money</option>
                            <option value="airtel_money" {{ old('payment_method') === 'airtel_money' ? 'selected' : '' }}>Airtel Money</option>
                            <option value="bank_transfer" {{ old('payment_method') === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer / Deposit Slip</option>
                            <option value="other" {{ old('payment_method') === 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                        @error('payment_method')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="payment_reference" class="form-label fw-bold" style="font-size: 13px;">
                            Channel Transaction ID / Ref
                        </label>
                        <input type="text" name="payment_reference" id="payment_reference"
                               class="form-control @error('payment_reference') is-invalid @enderror"
                               placeholder="e.g. MM91823741 or Bank Slip #0912"
                               value="{{ old('payment_reference') }}">
                        @error('payment_reference')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label" style="font-size: 13px;">Transaction Notes (Optional)</label>
                    <textarea name="notes" id="notes" rows="2" class="form-control"
                              placeholder="Any notes or remarks on this installment payment...">{{ old('notes') }}</textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('tenant.repayments.index') }}" class="btn btn-brand-outline">Cancel</a>
                    <button type="submit" class="btn btn-brand-primary" id="submitBtn">
                        <i class="bi bi-check-circle me-1"></i> Record Repayment
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Live Balance Projection Box --}}
    <div class="col-lg-4">
        <div class="sacco-card sticky-top" style="top: 20px;">
            <div class="sacco-card-header pb-2">
                <h6 class="mb-0 fw-bold">Repayment Impact Preview</h6>
            </div>

            <div class="list-group list-group-flush mb-3" style="font-size: 13px;">
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Borrower:</span>
                    <span class="fw-semibold text-dark" id="previewBorrower">Select loan</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Current Balance:</span>
                    <span class="fw-bold font-monospace" id="previewCurrentBalance" style="color: var(--color-teal);">UGX 0</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0">
                    <span class="text-muted">Payment Amount:</span>
                    <span class="fw-bold font-monospace text-success" id="previewPayment">UGX 0</span>
                </div>
                <div class="list-group-item d-flex justify-content-between px-0 border-top">
                    <span class="fw-bold">New Remaining Balance:</span>
                    <span class="fw-bold font-monospace fs-6" id="previewNewBalance" style="color: var(--color-black);">UGX 0</span>
                </div>
            </div>

            {{-- Overpayment Alert --}}
            <div id="overpaymentAlert" class="alert alert-danger p-2 mb-3 d-none" style="font-size: 12px;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> Amount exceeds current remaining balance. Maximum payable is <strong id="maxPayableText">UGX 0</strong>.
            </div>

            {{-- Fully Paid Alert --}}
            <div id="fullyPaidAlert" class="alert alert-success p-2 mb-3 d-none" style="font-size: 12px;">
                <i class="bi bi-check-circle-fill me-1"></i> This payment will completely settle the loan! Status will automatically transition to <strong>Closed</strong>.
            </div>

            <div class="p-2 bg-light rounded text-muted" style="font-size: 11px; line-height: 1.4;">
                <i class="bi bi-shield-lock me-1"></i> Once submitted, this repayment cannot be edited or deleted. Mistakes are corrected with a new entry.
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const loanSelect = document.getElementById('loan_id');
    const amountInput = document.getElementById('amount');
    const submitBtn = document.getElementById('submitBtn');

    const previewBorrower = document.getElementById('previewBorrower');
    const previewCurrentBalance = document.getElementById('previewCurrentBalance');
    const previewPayment = document.getElementById('previewPayment');
    const previewNewBalance = document.getElementById('previewNewBalance');
    const overpaymentAlert = document.getElementById('overpaymentAlert');
    const fullyPaidAlert = document.getElementById('fullyPaidAlert');
    const maxPayableText = document.getElementById('maxPayableText');

    function updateCalculations() {
        const selectedOption = loanSelect.options[loanSelect.selectedIndex];
        const currentBalance = selectedOption ? (parseFloat(selectedOption.getAttribute('data-balance')) || 0) : 0;
        const borrower = selectedOption ? selectedOption.getAttribute('data-member') : 'Select loan';
        const payment = parseFloat(amountInput.value) || 0;

        previewBorrower.textContent = borrower || 'Select loan';
        previewCurrentBalance.textContent = 'UGX ' + currentBalance.toLocaleString();
        previewPayment.textContent = 'UGX ' + payment.toLocaleString();

        const newBalance = Math.max(0, currentBalance - payment);
        previewNewBalance.textContent = 'UGX ' + newBalance.toLocaleString();
        maxPayableText.textContent = 'UGX ' + currentBalance.toLocaleString();

        if (payment > (currentBalance + 0.01) && currentBalance > 0) {
            overpaymentAlert.classList.remove('d-none');
            fullyPaidAlert.classList.add('d-none');
            submitBtn.disabled = true;
        } else {
            overpaymentAlert.classList.add('d-none');
            submitBtn.disabled = false;

            if (currentBalance > 0 && Math.abs(currentBalance - payment) <= 0.01) {
                fullyPaidAlert.classList.remove('d-none');
            } else {
                fullyPaidAlert.classList.add('d-none');
            }
        }
    }

    loanSelect.addEventListener('change', updateCalculations);
    amountInput.addEventListener('input', updateCalculations);

    updateCalculations();
});
</script>
@endsection
