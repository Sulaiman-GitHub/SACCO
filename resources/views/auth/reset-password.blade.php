@extends('layouts.guest')

@section('title', 'Choose a New Password — SACCO Credit Intelligence')

@section('card')
        <h2 style="font-size: 16px; font-weight: 700;" class="mb-1">Choose a new password</h2>
        <p class="mb-3" style="font-size: 13px; color: var(--color-slate);">
            Use at least 10 characters.
        </p>

        <form method="POST" action="{{ route('password.update', $token) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $email) }}" required placeholder="name@sacco.org">
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters">
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="10" autocomplete="new-password" placeholder="Repeat the password">
            </div>

            <button type="submit" class="btn btn-submit" data-busy-label="Saving...">
                Change Password
            </button>
        </form>
@endsection
