@extends('layouts.guest')

@section('title', 'Forgot Password — SACCO Credit Intelligence')

@section('card')
        <h2 style="font-size: 16px; font-weight: 700;" class="mb-1">Forgot your password?</h2>
        <p class="mb-3" style="font-size: 13px; color: var(--color-slate);">
            Enter your work email address and we will send you a link to choose a new password.
        </p>

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autofocus placeholder="name@sacco.org">
            </div>

            <button type="submit" class="btn btn-submit" data-busy-label="Sending...">
                Send Reset Link
            </button>

            <div class="text-center mt-3">
                <a href="{{ route('login') }}" class="card-link">Back to sign in</a>
            </div>
        </form>
@endsection
