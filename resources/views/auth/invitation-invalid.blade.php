@extends('layouts.guest')

@section('title', 'Invitation Not Valid — SACCO Credit Intelligence')

@section('card')
        <h2 style="font-size: 16px; font-weight: 700;" class="mb-1">This invitation link cannot be used</h2>
        <p class="mb-3" style="font-size: 13px; color: var(--color-slate);">
            It may have expired, already been used, or been replaced by a newer invitation.
            Ask your administrator to send a new invitation, or sign in if you have already set your password.
        </p>

        <a href="{{ route('login') }}" class="btn btn-submit">Go to sign in</a>
@endsection
