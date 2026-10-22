@extends('layouts.app')

@section('title', 'Register Member')
@section('page_title', 'Register New SACCO Member')

@section('topbar_actions')
    <a href="{{ route('tenant.members.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Members
    </a>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        {{-- Privacy & Regulatory Notice --}}
        <div class="alert alert-light border d-flex align-items-start mb-4" style="border-radius: 4px; border-left: 4px solid var(--color-teal) !important;">
            <i class="bi bi-shield-lock-fill me-3 fs-4" style="color: var(--color-teal);"></i>
            <div>
                <div class="fw-bold" style="color: var(--color-black); font-size: 13px;">Data Protection and Privacy (Uganda Data Protection and Privacy Act, 2019)</div>
                <div class="text-muted" style="font-size: 12px; line-height: 1.4;">
                    National ID numbers are not stored in full. The system keeps only a protected version, used to spot duplicate registrations, and a masked copy for display. Keep the member's ID card details confidential within your SACCO.
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('tenant.members.store') }}">
            @csrf

            {{-- Section 1: Membership Identification --}}
            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">1. Membership Details</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Core identification within this SACCO</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="member_number" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Member Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="member_number" name="member_number" class="form-control @error('member_number') is-invalid @enderror" value="{{ old('member_number', $nextMemberNumber) }}" required>
                        @error('member_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="join_date" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Date Joined <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="join_date" name="join_date" class="form-control @error('join_date') is-invalid @enderror" value="{{ old('join_date', date('Y-m-d')) }}" required>
                        @error('join_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="status" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Account Status
                        </label>
                        <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                            <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            <option value="suspended" {{ old('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                        </select>
                        @error('status')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Section 2: Personal Bio-Data --}}
            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">2. Personal Bio-Data</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Full name and identity credentials</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="full_name" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Full Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="full_name" name="full_name" class="form-control @error('full_name') is-invalid @enderror" placeholder="e.g. Okello Ronald" value="{{ old('full_name') }}" required>
                        @error('full_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="national_id" class="form-label" style="font-size: 12px; font-weight: 600;">
                            National ID (NIN) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-shield-lock text-muted"></i></span>
                            <input type="text" id="national_id" name="national_id" class="form-control @error('national_id') is-invalid @enderror @error('national_id_hash') is-invalid @enderror" placeholder="e.g. CM820141029ABC" value="{{ old('national_id') }}" required>
                        </div>
                        @error('national_id')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                        @error('national_id_hash')
                            <div class="text-danger mt-1" style="font-size: 12px;">{{ $message }}</div>
                        @enderror
                        <div class="form-text" style="font-size: 11px;">The full number is not kept. Only a masked version is shown afterwards.</div>
                    </div>

                    <div class="col-md-4">
                        <label for="date_of_birth" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Date of Birth
                        </label>
                        <input type="date" id="date_of_birth" name="date_of_birth" class="form-control @error('date_of_birth') is-invalid @enderror" value="{{ old('date_of_birth') }}">
                        @error('date_of_birth')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="gender" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Gender
                        </label>
                        <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror">
                            <option value="">Select Gender</option>
                            <option value="male" {{ old('gender') === 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ old('gender') === 'female' ? 'selected' : '' }}>Female</option>
                            <option value="other" {{ old('gender') === 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                        @error('gender')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="phone" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Phone Number <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" placeholder="+256 700 000000" value="{{ old('phone') }}" required>
                        @error('phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Section 3: Contact & Economic Livelihood --}}
            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">3. Contact & Livelihood</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Economic profile for future credit risk assessment</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="email" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Email Address (Optional)
                        </label>
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" placeholder="member@example.ug" value="{{ old('email') }}">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="occupation" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Occupation / Livelihood
                        </label>
                        <input type="text" id="occupation" name="occupation" class="form-control @error('occupation') is-invalid @enderror" placeholder="e.g. Produce Trader, Boda Boda, Teacher" value="{{ old('occupation') }}">
                        @error('occupation')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="monthly_income" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Estimated Monthly Income (UGX)
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="font-size: 12px;">UGX</span>
                            <input type="number" step="any" id="monthly_income" name="monthly_income" class="form-control @error('monthly_income') is-invalid @enderror" placeholder="e.g. 1500000" value="{{ old('monthly_income') }}">
                        </div>
                        @error('monthly_income')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="address" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Residential Address / Location
                        </label>
                        <input type="text" id="address" name="address" class="form-control @error('address') is-invalid @enderror" placeholder="e.g. Plot 14, Kawempe Division, Kampala" value="{{ old('address') }}">
                        @error('address')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Section 4: Next of Kin --}}
            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">4. Next of Kin Information</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Designated emergency and KYC contact</div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="next_of_kin_name" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Next of Kin Name
                        </label>
                        <input type="text" id="next_of_kin_name" name="next_of_kin_name" class="form-control @error('next_of_kin_name') is-invalid @enderror" placeholder="Full name of next of kin" value="{{ old('next_of_kin_name') }}">
                        @error('next_of_kin_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="next_of_kin_relationship" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Relationship
                        </label>
                        <input type="text" id="next_of_kin_relationship" name="next_of_kin_relationship" class="form-control @error('next_of_kin_relationship') is-invalid @enderror" placeholder="e.g. Spouse, Brother, Parent" value="{{ old('next_of_kin_relationship') }}">
                        @error('next_of_kin_relationship')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="next_of_kin_phone" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Next of Kin Phone
                        </label>
                        <input type="text" id="next_of_kin_phone" name="next_of_kin_phone" class="form-control @error('next_of_kin_phone') is-invalid @enderror" placeholder="+256 700 000000" value="{{ old('next_of_kin_phone') }}">
                        @error('next_of_kin_phone')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label" style="font-size: 12px; font-weight: 600;">
                            Additional Internal Notes
                        </label>
                        <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror" placeholder="Any special notes or observations regarding member registration...">{{ old('notes') }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- Section 5: Member Consent --}}
            <div class="sacco-card mb-4">
                <div class="sacco-card-header">
                    <div>
                        <h2 style="font-size: 15px; margin: 0;">5. Member Consent</h2>
                        <div style="font-size: 12px; color: var(--color-slate);">Recorded with your name, the date and the notice version (Uganda Data Protection and Privacy Act, 2019)</div>
                    </div>
                </div>

                @include('tenant.members._consent_fields')
            </div>

            {{-- Form Actions --}}
            <div class="d-flex align-items-center justify-content-between mb-5">
                <a href="{{ route('tenant.members.index') }}" class="btn btn-brand-outline">Cancel</a>
                <button type="submit" class="btn btn-brand-primary px-4 py-2">
                    <i class="bi bi-check-circle me-1"></i> Complete Registration
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
