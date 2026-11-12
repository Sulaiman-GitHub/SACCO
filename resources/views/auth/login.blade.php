@extends('layouts.guest')

@section('card')
        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autofocus placeholder="name@sacco.org">
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="password" class="form-label">Password</label>
                </div>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required placeholder="Enter your password">
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember" style="font-size: 13px; color: var(--color-slate);">
                    Remember my session
                </label>
            </div>

            <button type="submit" class="btn btn-submit" data-busy-label="Signing in...">
                Sign In
            </button>

            <div class="text-center mt-3">
                <a href="{{ route('password.request') }}" class="card-link">Forgot your password?</a>
            </div>
        </form>
@endsection
