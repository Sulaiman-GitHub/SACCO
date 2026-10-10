@extends('layouts.app')

@section('title', 'Record Savings Transaction')
@section('page_title', 'Record Savings Transaction')

@section('topbar_actions')
    <a href="{{ route('tenant.savings.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Savings
    </a>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        {{-- Append-Only Ledger Notice --}}
        <div class="alert alert-light border d-flex align-items-start mb-4" style="border-radius: 4px; border-left: 4px solid var(--color-teal) !important;">
            <i class="bi bi-shield-check me-3 fs-4" style="color: var(--color-teal);"></i>
            <div>
                <div class="fw-bold" style="color: var(--color-black); font-size: 13px;">Records cannot be changed once posted</div>
                <div class="text-muted" style="font-size: 12px; line-height: 1.4;">
                    Once submitted, a savings transaction cannot be edited or deleted. If a mistake is made, record a correcting transaction.
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('tenant.savings.store') }}">
            @csrf

            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">Transaction Particulars</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Generated Reference: <code>{{ $nextReference }}</code></div>
                    </div>
                </div>

                <div class="row g-3">
                    {{-- Member Selection --}}
                    <div class="col-12">
                        <label for="member_id" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Select Member <span class="text-danger">*</span>
                        </label>
                        <select id="member_id" name="member_id" class="form-select @error('member_id') is-invalid @enderror" required onchange="updateMemberInfo(this)">
                            <option value="">-- Choose Member --</option>
                            @foreach($members as $m)
                                <option value="{{ $m->id }}"
                                    data-balance="{{ $m->savings_balance }}"
                                    data-name="{{ $m->full_name }}"
                                    data-number="{{ $m->member_number }}"
                                    {{ old('member_id', $selectedMember?->id) == $m->id ? 'selected' : '' }}>
                                    {{ $m->member_number }} &bull; {{ $m->full_name }} ({{ $m->phone }})
                                </option>
                            @endforeach
                        </select>
                        @error('member_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror

                        {{-- Current Balance Display Tile --}}
                        <div id="balance_indicator" class="mt-2 p-2 px-3 border rounded bg-light d-flex align-items-center justify-content-between" style="display: {{ $selectedMember ? 'flex' : 'none' }} !important;">
                            <span style="font-size: 12px; color: var(--color-slate);">Current Available Savings Balance:</span>
                            <span id="current_balance_text" class="fw-bold font-monospace" style="color: var(--color-teal); font-size: 14px;">
                                UGX {{ number_format($selectedMember?->savings_balance ?? 0, 2) }}
                            </span>
                        </div>
                    </div>

                    {{-- Transaction Type --}}
                    <div class="col-md-6">
                        <label class="form-label" style="font-size: 12px; font-weight: 600;">
                            Transaction Type <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-3 mt-1">
                            <div class="form-check p-2 px-4 border rounded flex-grow-1" style="background: #F8FBF9; border-color: rgba(31, 174, 92, 0.4) !important;">
                                <input class="form-check-input" type="radio" name="type" id="type_deposit" value="deposit" {{ old('type', 'deposit') === 'deposit' ? 'checked' : '' }}>
                                <label class="form-check-label fw-bold text-success" for="type_deposit" style="font-size: 13px;">
                                    <i class="bi bi-arrow-down-left me-1"></i> Deposit (+)
                                </label>
                            </div>
                            <div class="form-check p-2 px-4 border rounded flex-grow-1" style="background: #FFFDF9; border-color: rgba(217, 143, 43, 0.4) !important;">
                                <input class="form-check-input" type="radio" name="type" id="type_withdrawal" value="withdrawal" {{ old('type') === 'withdrawal' ? 'checked' : '' }}>
                                <label class="form-check-label fw-bold text-warning" for="type_withdrawal" style="font-size: 13px; color: #B36B15 !important;">
                                    <i class="bi bi-arrow-up-right me-1"></i> Withdrawal (−)
                                </label>
                            </div>
                        </div>
                        @error('type')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Amount --}}
                    <div class="col-md-6">
                        <label for="amount" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Amount (UGX) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light fw-bold" style="font-size: 12px;">UGX</span>
                            <input type="number" step="any" id="amount" name="amount" class="form-control font-monospace @error('amount') is-invalid @enderror" placeholder="50000" value="{{ old('amount') }}" required>
                        </div>
                        @error('amount')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                        <div class="form-text" style="font-size: 11px;">Minimum transaction: UGX 1,000.</div>
                    </div>

                    {{-- Payment Method --}}
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Payment Channel <span class="text-danger">*</span>
                        </label>
                        <select id="payment_method" name="payment_method" class="form-select @error('payment_method') is-invalid @enderror" required>
                            <option value="cash" {{ old('payment_method') === 'cash' ? 'selected' : '' }}>Cash Over the Counter</option>
                            <option value="mtn_momo" {{ old('payment_method') === 'mtn_momo' ? 'selected' : '' }}>MTN Mobile Money</option>
                            <option value="airtel_money" {{ old('payment_method') === 'airtel_money' ? 'selected' : '' }}>Airtel Money</option>
                            <option value="bank_transfer" {{ old('payment_method') === 'bank_transfer' ? 'selected' : '' }}>Bank Transfer / Deposit Slip</option>
                            <option value="other" {{ old('payment_method') === 'other' ? 'selected' : '' }}>Other Channel</option>
                        </select>
                        @error('payment_method')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Payment Reference --}}
                    <div class="col-md-6">
                        <label for="payment_reference" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Payment Slip / MoMo Txn ID
                        </label>
                        <input type="text" id="payment_reference" name="payment_reference" class="form-control @error('payment_reference') is-invalid @enderror" placeholder="e.g. MM9823412 or Bank Slip #0912" value="{{ old('payment_reference') }}">
                        @error('payment_reference')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Transaction Date --}}
                    <div class="col-md-6">
                        <label for="transaction_date" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Effective Transaction Date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="transaction_date" name="transaction_date" class="form-control @error('transaction_date') is-invalid @enderror" value="{{ old('transaction_date', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}" required>
                        @error('transaction_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Narration / Notes --}}
                    <div class="col-md-6">
                        <label for="notes" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Narration / Notes
                        </label>
                        <input type="text" id="notes" name="notes" class="form-control @error('notes') is-invalid @enderror" placeholder="e.g. Weekly compulsory savings" value="{{ old('notes') }}">
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Form Actions --}}
            <div class="d-flex align-items-center justify-content-between mb-5">
                <a href="{{ route('tenant.savings.index') }}" class="btn btn-brand-outline">Cancel</a>
                <button type="submit" class="btn btn-brand-primary px-4 py-2">
                    <i class="bi bi-check2-circle me-1"></i> Record Transaction
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
function updateMemberInfo(select) {
    const option = select.options[select.selectedIndex];
    const indicator = document.getElementById('balance_indicator');
    const balanceText = document.getElementById('current_balance_text');

    if (option && option.value) {
        const bal = parseFloat(option.getAttribute('data-balance') || 0);
        balanceText.innerText = 'UGX ' + bal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        indicator.style.display = 'flex';
    } else {
        indicator.style.display = 'none';
    }
}
</script>
@endsection
