@extends('layouts.guest')

@section('title', 'Set Your Password — SACCO Credit Intelligence')

@section('card')
        <h2 style="font-size: 16px; font-weight: 700;" class="mb-1">Welcome, {{ $staffName }}</h2>
        <p class="mb-3" style="font-size: 13px; color: var(--color-slate);">
            Choose a password to activate your account. Use at least 10 characters.
        </p>

        <form method="POST" action="{{ route('staff.invitation.accept', $token) }}">
            @csrf

            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required minlength="10" autocomplete="new-password" autofocus placeholder="At least 10 characters">
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="10" autocomplete="new-password" placeholder="Repeat the password">
            </div>

            <button type="submit" class="btn btn-submit" data-busy-label="Saving...">
                Activate My Account
            </button>
        </form>
@endsection
