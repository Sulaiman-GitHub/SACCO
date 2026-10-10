@extends('layouts.app')

@section('title', 'Add Staff Member')
@section('page_title', 'Add Staff Member')

@section('topbar_actions')
    <a href="{{ route('tenant.staff.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Staff List
    </a>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Create New User Account for {{ Auth::user()->sacco->name }}</h2>
            </div>

            <form method="POST" action="{{ route('tenant.staff.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required placeholder="e.g. Sarah Namubiru">
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required placeholder="sarah@sacco.org">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}" placeholder="+256 700 123456">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Role Assignment <span class="text-danger">*</span></label>
                    <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="loan_officer" {{ old('role') == 'loan_officer' ? 'selected' : '' }}>Loan Officer (Underwriting & Member Reviews)</option>
                        <option value="sacco_admin" {{ old('role') == 'sacco_admin' ? 'selected' : '' }}>SACCO Administrator (Full Cooperative Management)</option>
                    </select>
                    @error('role') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required placeholder="Minimum 8 characters">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label for="password_confirmation" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required placeholder="Repeat password">
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('tenant.staff.index') }}" class="btn btn-brand-outline">Cancel</a>
                    <button type="submit" class="btn btn-brand-primary px-4">
                        <i class="bi bi-person-check-fill me-1"></i> Save Staff Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
