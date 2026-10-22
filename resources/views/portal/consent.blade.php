@extends('layouts.portal')

@php
    use App\Models\ConsentChangeRequest;
@endphp

@section('title', 'My consent')
@section('page_title', 'My consent')

@section('content')
<div class="row g-4">
    <div class="col-lg-6">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">What you have agreed to</h2>
            </div>

            @if($member->hasRecordedConsent())
                <ul class="list-unstyled mb-0" style="font-size: 13px;">
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">SACCO using your information</span>
                        <span class="fw-semibold">Agreed</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Credit scoring</span>
                        <span class="fw-semibold">{{ $member->scoring_consent ? 'Agreed' : 'Declined' }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">Notice version</span>
                        <span class="fw-semibold">{{ $member->consent_version }}</span>
                    </li>
                    <li class="d-flex justify-content-between py-2">
                        <span class="text-muted">Recorded</span>
                        <span class="fw-semibold">{{ $member->consented_at?->format('d M Y') }}</span>
                    </li>
                </ul>
            @else
                <p class="mb-0" style="font-size: 13px;">The SACCO has no consent record for you yet. Until it does, you are not credit scored, and loan applications are decided by an officer.</p>
            @endif
        </div>

        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">Ask for a change</h2>
            </div>
            @if(count($requestableTypes) > 0)
                <form method="POST" action="{{ route('portal.consent_requests.store') }}">
                    @csrf
                    @foreach($requestableTypes as $type)
                        <label class="choice" for="type_{{ $type }}">
                            <input type="radio" name="request_type" id="type_{{ $type }}" value="{{ $type }}" {{ old('request_type') === $type ? 'checked' : '' }} required>
                            <span>{{ ConsentChangeRequest::TYPE_LABELS[$type] }}</span>
                        </label>
                    @endforeach
                    <div class="mb-3 mt-3">
                        <label for="member_note" class="form-label" style="font-size: 13px; font-weight: 600;">Anything you want to tell the SACCO (optional)</label>
                        <textarea class="form-control" id="member_note" name="member_note" rows="2" maxlength="500">{{ old('member_note') }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-brand-primary btn-block-mobile">Send request</button>
                    <div class="text-muted mt-2" style="font-size: 12px;">
                        This only sends a request. Nothing changes until a SACCO staff member has dealt with it. Declining credit scoring never stops you applying for a loan.
                    </div>
                </form>
            @else
                <div class="text-muted" style="font-size: 13px;">You already have a request waiting for each change that is available to you.</div>
            @endif
        </div>
    </div>

    <div class="col-lg-6">
        <div class="sacco-card">
            <div class="sacco-card-header">
                <h2 style="font-size: 15px; margin: 0;">My requests</h2>
            </div>
            @forelse($requests as $consentRequest)
                <div class="py-2 border-bottom" style="font-size: 13px;">
                    <div class="d-flex justify-content-between gap-2">
                        <span>{{ $consentRequest->label() }}</span>
                        <span class="{{ $consentRequest->status === 'processed' ? 'badge-active' : ($consentRequest->status === 'dismissed' ? 'badge-inactive' : 'badge-neutral') }}">
                            {{ $consentRequest->status === 'pending' ? 'Waiting' : ucfirst($consentRequest->status) }}
                        </span>
                    </div>
                    <div class="text-muted" style="font-size: 12px;">Sent {{ $consentRequest->created_at->format('d M Y') }}</div>
                    @if($consentRequest->handler_note)
                        <div class="text-muted" style="font-size: 12px;">SACCO note: {{ $consentRequest->handler_note }}</div>
                    @endif
                </div>
            @empty
                <div class="text-muted" style="font-size: 13px;">You have not sent any requests.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
