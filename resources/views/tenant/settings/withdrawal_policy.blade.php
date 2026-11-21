@extends('layouts.app')

@section('title', 'Withdrawal Rules')
@section('page_title', 'Withdrawal Rules')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Withdrawal rules for {{ Auth::user()->sacco->name }}</h2>
            </div>

            <p class="text-muted" style="font-size: 13px;">
                These rules apply when members ask to withdraw savings from their portal. The loan rule and the daily limit also apply to withdrawals recorded at the counter. The values shown first are suggestions, so please review them for your SACCO.
            </p>

            <form method="POST" action="{{ route('tenant.withdrawal_policy.update') }}">
                @csrf
                @method('PUT')

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="notice_days" class="form-label">Notice before a withdrawal (days)</label>
                        <input type="number" min="0" max="90" class="form-control @error('notice_days') is-invalid @enderror" id="notice_days" name="notice_days" value="{{ old('notice_days', $policy->notice_days) }}" required>
                        <div class="form-text">A request can be approved once this many days have passed. Use 0 for no notice.</div>
                        @error('notice_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="request_expiry_days" class="form-label">Days before an unpaid request expires</label>
                        <input type="number" min="1" max="365" class="form-control @error('request_expiry_days') is-invalid @enderror" id="request_expiry_days" name="request_expiry_days" value="{{ old('request_expiry_days', $policy->request_expiry_days) }}" required>
                        <div class="form-text">Counted from the day the money becomes available.</div>
                        @error('request_expiry_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="loan_cover_mode" class="form-label">When the member has a loan</label>
                    <select class="form-select @error('loan_cover_mode') is-invalid @enderror" id="loan_cover_mode" name="loan_cover_mode" required>
                        @foreach(\App\Models\WithdrawalPolicy::COVER_MODE_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('loan_cover_mode', $policy->loan_cover_mode) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">The second option lets a member withdraw only what is above the loan amount divided by the maximum loan to savings ratio in the lending policy. Without a ratio it works like the first option.</div>
                    @error('loan_cover_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="early_withdrawal_fee_percent" class="form-label">Early withdrawal fee (%)</label>
                        <input type="number" step="0.01" min="0" max="20" class="form-control @error('early_withdrawal_fee_percent') is-invalid @enderror" id="early_withdrawal_fee_percent" name="early_withdrawal_fee_percent" value="{{ old('early_withdrawal_fee_percent', $policy->early_withdrawal_fee_percent) }}" required>
                        <div class="form-text">Members can only withdraw before the notice period ends when this is above 0. The fee is taken from the payout.</div>
                        @error('early_withdrawal_fee_percent') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="daily_limit_per_member" class="form-label">Daily limit per member (UGX)</label>
                        <input type="number" step="1" min="1000" class="form-control @error('daily_limit_per_member') is-invalid @enderror" id="daily_limit_per_member" name="daily_limit_per_member" value="{{ old('daily_limit_per_member', $policy->daily_limit_per_member !== null ? (int) $policy->daily_limit_per_member : null) }}" placeholder="No limit">
                        <div class="form-text">Leave blank for no limit.</div>
                        @error('daily_limit_per_member') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="admin_approval_threshold" class="form-label">Administrator approves above (UGX)</label>
                        <input type="number" step="1" min="0" class="form-control @error('admin_approval_threshold') is-invalid @enderror" id="admin_approval_threshold" name="admin_approval_threshold" value="{{ old('admin_approval_threshold', $policy->admin_approval_threshold !== null ? (int) $policy->admin_approval_threshold : null) }}" placeholder="Administrator always">
                        <div class="form-text">Requests above this amount can only be approved by a SACCO administrator. Leave blank if an administrator should approve every request.</div>
                        @error('admin_approval_threshold') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="security_hold_days" class="form-label">Hold after a password or phone change (days)</label>
                        <input type="number" min="0" max="30" class="form-control @error('security_hold_days') is-invalid @enderror" id="security_hold_days" name="security_hold_days" value="{{ old('security_hold_days', $policy->security_hold_days) }}" required>
                        <div class="form-text">After a member changes their portal password or phone number, new requests are blocked for this many days. Use 0 to switch it off.</div>
                        @error('security_hold_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="payer_must_differ_from_approver" class="form-label">Approving and paying out</label>
                    <select class="form-select @error('payer_must_differ_from_approver') is-invalid @enderror" id="payer_must_differ_from_approver" name="payer_must_differ_from_approver" required>
                        @foreach(\App\Models\WithdrawalPolicy::PAYER_RULE_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('payer_must_differ_from_approver', $policy->payer_must_differ_from_approver) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">This decides whether the staff member who approved a withdrawal may also record the payout. Two different people is safer, because one person cannot approve and pay alone. "For large requests" applies to requests above the administrator approval amount. If your SACCO has only one staff member, choose "Never", otherwise large requests can never be paid out.</div>
                    @error('payer_must_differ_from_approver') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand-primary px-4">Save Rules</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
