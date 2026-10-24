@extends('layouts.portal')

@section('title', 'Sign in')
@section('page_title', 'Member sign in')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="sacco-card">
            <p class="text-muted" style="font-size: 13px;">
                Sign in with the login name and password your SACCO gave you. You can only view your own account here.
            </p>
            <form method="POST" action="{{ route('portal.login.attempt') }}">
                @csrf
                <div class="mb-3">
                    <label for="portal_username" class="form-label" style="font-size: 13px; font-weight: 600;">Login name</label>
                    <input type="text" class="form-control @error('portal_username') is-invalid @enderror" id="portal_username" name="portal_username" value="{{ old('portal_username') }}" autocomplete="username" autofocus required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label" style="font-size: 13px; font-weight: 600;">Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-brand-primary w-100" data-busy-label="Signing in...">Sign in</button>
            </form>
        </div>
        <p class="text-muted text-center" style="font-size: 12px;">SACCO staff sign in <a href="{{ route('login') }}">here</a>.</p>
    </div>
</div>
@endsection
