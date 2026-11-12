@extends('layouts.app')

@section('title', 'Set Temporary Password')
@section('page_title', 'Set Temporary Password')

@section('topbar_actions')
    <a href="{{ route('tenant.staff.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to Staff List
    </a>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Temporary password for {{ $staffMember->name }}</h2>
            </div>

            <div class="alert alert-light border mb-3" style="font-size: 12px; color: var(--color-slate);">
                <i class="bi bi-info-circle-fill me-1 text-primary"></i>
                Use this only when email is not working. Give the password to {{ $staffMember->name }} in person, and any invitation already sent will stop working.
            </div>

            <form method="POST" action="{{ route('tenant.staff.temporary_password.update', $staffMember) }}">
                @csrf

                <div class="mb-3">
                    <label for="password" class="form-label">Temporary Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required autocomplete="new-password" placeholder="Minimum 8 characters">
                    @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" placeholder="Repeat password">
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('tenant.staff.index') }}" class="btn btn-brand-outline">Cancel</a>
                    <button type="submit" class="btn btn-brand-primary px-4">
                        <i class="bi bi-key-fill me-1"></i> Set Temporary Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
