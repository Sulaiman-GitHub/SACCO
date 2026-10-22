@extends('layouts.portal')

@section('title', 'Change password')
@section('page_title', 'Change your password')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="sacco-card">
            @if($member->portal_must_change_password)
                <div class="alert alert-warning" style="font-size: 13px;">
                    You are using a temporary password from your SACCO. Choose your own password to continue.
                </div>
            @endif
            <form method="POST" action="{{ route('portal.password.update') }}">
                @csrf
                @method('PUT')
                <div class="mb-3">
                    <label for="current_password" class="form-label" style="font-size: 13px; font-weight: 600;">Current password</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label" style="font-size: 13px; font-weight: 600;">New password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" required>
                    <div class="text-muted mt-1" style="font-size: 12px;">At least 8 characters, with letters and numbers.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label" style="font-size: 13px; font-weight: 600;">Confirm new password</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
                </div>
                <button type="submit" class="btn btn-brand-primary btn-block-mobile">Save password</button>
            </form>
        </div>
    </div>
</div>
@endsection
