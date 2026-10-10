@extends('layouts.app')

@section('title', 'Lending Policy')
@section('page_title', 'Lending Policy')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Lending policy for {{ Auth::user()->sacco->name }}</h2>
            </div>

            <p class="text-muted" style="font-size: 13px;">
                The credit risk score runs from 0 (low risk) to 100 (high risk). These limits turn it into a recommendation on every new loan application. Changes apply to new applications only.
            </p>

            <form method="POST" action="{{ route('tenant.lending_policy.update') }}">
                @csrf
                @method('PUT')

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="approve_below_score" class="form-label">Recommend approval below score</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control @error('approve_below_score') is-invalid @enderror" id="approve_below_score" name="approve_below_score" value="{{ old('approve_below_score', $policy->approve_below_score) }}" required>
                        @error('approve_below_score') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="reject_at_or_above_score" class="form-label">Recommend rejection at or above score</label>
                        <input type="number" step="0.01" min="0" max="100" class="form-control @error('reject_at_or_above_score') is-invalid @enderror" id="reject_at_or_above_score" name="reject_at_or_above_score" value="{{ old('reject_at_or_above_score', $policy->reject_at_or_above_score) }}" required>
                        @error('reject_at_or_above_score') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 text-muted" style="font-size: 12px;">Scores between the two limits require a guarantor.</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="max_loan_to_savings_ratio" class="form-label">Maximum loan to savings ratio</label>
                        <input type="number" step="0.01" min="0.1" class="form-control @error('max_loan_to_savings_ratio') is-invalid @enderror" id="max_loan_to_savings_ratio" name="max_loan_to_savings_ratio" value="{{ old('max_loan_to_savings_ratio', $policy->max_loan_to_savings_ratio) }}" placeholder="No cap">
                        <div class="form-text">Loans above this multiple of savings require a guarantor. Leave blank for no cap.</div>
                        @error('max_loan_to_savings_ratio') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="min_tenure_months" class="form-label">Minimum membership tenure (months)</label>
                        <input type="number" min="0" max="120" class="form-control @error('min_tenure_months') is-invalid @enderror" id="min_tenure_months" name="min_tenure_months" value="{{ old('min_tenure_months', $policy->min_tenure_months) }}" required>
                        <div class="form-text">Newer members require a guarantor.</div>
                        @error('min_tenure_months') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input type="hidden" name="reject_on_past_default" value="0">
                    <input type="checkbox" class="form-check-input" id="reject_on_past_default" name="reject_on_past_default" value="1" @checked(old('reject_on_past_default', $policy->reject_on_past_default))>
                    <label for="reject_on_past_default" class="form-check-label">Recommend rejection for members with a previous default (otherwise a guarantor is required)</label>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand-primary px-4">Save Policy</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
