@extends('layouts.app')

@section('title', 'Onboard New SACCO')
@section('page_title', 'Onboard New SACCO')

@section('topbar_actions')
    <a href="{{ route('super_admin.saccos.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to SACCOs
    </a>
@endsection

@section('content')
<form method="POST" action="{{ route('super_admin.saccos.store') }}">
    @csrf

    <div class="row g-4">
        {{-- Section 1: SACCO Organization Details --}}
        <div class="col-lg-6">
            <div class="sacco-card h-100">
                <div class="sacco-card-header">
                    <h2 style="font-size: 16px; margin: 0;"><i class="bi bi-building me-2 text-success"></i> Cooperative Organization Information</h2>
                </div>

                <div class="mb-3">
                    <label for="name" class="form-label">SACCO Legal Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. Kampala Teachers SACCO Ltd">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="tier" class="form-label">Regulatory Tier <span class="text-danger">*</span></label>
                        <select name="tier" id="tier" class="form-select @error('tier') is-invalid @enderror" required>
                            <option value="Tier 4" {{ old('tier') == 'Tier 4' ? 'selected' : '' }}>Tier 4 (Community Non-Deposit)</option>
                            <option value="Tier 3" {{ old('tier') == 'Tier 3' ? 'selected' : '' }}>Tier 3 (Small Credit Institution)</option>
                            <option value="Tier 2" {{ old('tier') == 'Tier 2' ? 'selected' : '' }}>Tier 2 (Medium Deposit-Taking)</option>
                            <option value="Tier 1" {{ old('tier') == 'Tier 1' ? 'selected' : '' }}>Tier 1 (Large Deposit-Taking)</option>
                        </select>
                        @error('tier') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="license_number" class="form-label">Registration / License No.</label>
                        <input type="text" class="form-control @error('license_number') is-invalid @enderror" id="license_number" name="license_number" value="{{ old('license_number') }}" placeholder="e.g. UMRA/SACCO/2026/089">
                        @error('license_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="email" class="form-label">Official SACCO Email</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" placeholder="info@teacherssacco.ug">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Official Phone</label>
                        <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}" placeholder="+256 700 000000">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="address" class="form-label">Physical Office Address</label>
                    <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address" rows="3" placeholder="Plot, Street, Town/District">{{ old('address') }}</textarea>
                    @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- Section 2: Initial SACCO Admin Credentials --}}
        <div class="col-lg-6">
            <div class="sacco-card h-100">
                <div class="sacco-card-header">
                    <h2 style="font-size: 16px; margin: 0;"><i class="bi bi-person-badge me-2 text-primary"></i> Primary SACCO Administrator</h2>
                </div>

                <div class="alert alert-light border mb-3" style="font-size: 12px; color: var(--color-slate);">
                    <i class="bi bi-info-circle-fill me-1 text-primary"></i> This user will be created as the initial <strong>SACCO Administrator</strong> for this SACCO. They will have full access to manage their members, configure risk thresholds, and invite loan officers.
                </div>

                <div class="mb-3">
                    <label for="admin_name" class="form-label">Administrator Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('admin_name') is-invalid @enderror" id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required placeholder="e.g. John Mukasa">
                    @error('admin_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="admin_email" class="form-label">Login Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control @error('admin_email') is-invalid @enderror" id="admin_email" name="admin_email" value="{{ old('admin_email') }}" required placeholder="mukasa@teacherssacco.ug">
                        @error('admin_email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="admin_phone" class="form-label">Phone Number</label>
                        <input type="text" class="form-control @error('admin_phone') is-invalid @enderror" id="admin_phone" name="admin_phone" value="{{ old('admin_phone') }}" placeholder="+256 772 123456">
                        @error('admin_phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="admin_password" class="form-label">Initial Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control @error('admin_password') is-invalid @enderror" id="admin_password" name="admin_password" required placeholder="Minimum 8 characters">
                        @error('admin_password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="admin_password_confirmation" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="admin_password_confirmation" name="admin_password_confirmation" required placeholder="Repeat password">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="btn btn-brand-primary w-100 py-2">
                        <i class="bi bi-check-lg me-1"></i> Complete Onboarding & Activate SACCO
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
