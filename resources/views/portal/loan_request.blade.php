@extends('layouts.portal')

@section('title', 'Request a loan')
@section('page_title', 'Request a loan')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Tell the SACCO what you need</h2>
            </div>

            @if($hasPending)
                <p class="mb-0" style="font-size: 13px;">You already have a loan request waiting for the SACCO. You can send another once it has been dealt with. <a href="{{ route('portal.loans') }}">See my loans</a>.</p>
            @else
                <p class="text-muted" style="font-size: 13px;">
                    The SACCO sets the interest, currently {{ rtrim(rtrim(number_format($policy->default_interest_rate, 2), '0'), '.') }}% a year. An officer reviews every request, so sending it does not mean it is approved.
                </p>

                <form method="POST" action="{{ route('portal.loan_requests.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="principal" class="form-label">Amount you want to borrow (UGX)</label>
                        <input type="number" inputmode="numeric" min="10000" step="1000" class="form-control @error('principal') is-invalid @enderror" id="principal" name="principal" value="{{ old('principal') }}" required>
                        <div class="form-text">At least UGX 10,000.</div>
                        @error('principal') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label for="term_months" class="form-label">Repay over (months)</label>
                        <input type="number" inputmode="numeric" min="1" max="120" class="form-control @error('term_months') is-invalid @enderror" id="term_months" name="term_months" value="{{ old('term_months') }}" required>
                        @error('term_months') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label for="purpose" class="form-label">What is the loan for? (optional)</label>
                        <input type="text" maxlength="255" class="form-control @error('purpose') is-invalid @enderror" id="purpose" name="purpose" value="{{ old('purpose') }}">
                        @error('purpose') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="stat-title mb-2">Guarantor (optional)</div>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label for="guarantor_name" class="form-label">Name</label>
                            <input type="text" maxlength="255" class="form-control @error('guarantor_name') is-invalid @enderror" id="guarantor_name" name="guarantor_name" value="{{ old('guarantor_name') }}">
                            @error('guarantor_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="guarantor_phone" class="form-label">Phone</label>
                            <input type="text" maxlength="50" class="form-control @error('guarantor_phone') is-invalid @enderror" id="guarantor_phone" name="guarantor_phone" value="{{ old('guarantor_phone') }}">
                            @error('guarantor_phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="guarantor_relationship" class="form-label">Relationship to you</label>
                            <input type="text" maxlength="100" class="form-control @error('guarantor_relationship') is-invalid @enderror" id="guarantor_relationship" name="guarantor_relationship" value="{{ old('guarantor_relationship') }}">
                            @error('guarantor_relationship') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <button type="submit" class="btn btn-brand-primary btn-block-mobile">Send request</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
