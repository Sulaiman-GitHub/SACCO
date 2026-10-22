@extends('layouts.app')

@section('title', 'Audit Log')
@section('page_title', 'Audit Log')

@section('topbar_actions')
    <form method="POST" action="{{ route('tenant.audit_log.verify') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-brand-outline"><i class="bi bi-shield-check me-1"></i> Verify Integrity</button>
    </form>
@endsection

@section('content')
@if(session('integrity'))
    @php $integrity = session('integrity'); @endphp
    @if($integrity['ok'])
        <div class="alert alert-success" style="font-size: 13px;">
            Integrity check passed. {{ number_format($integrity['checked']) }} entries verified, and every recorded savings and repayment matches the ledger.
        </div>
    @else
        <div class="alert alert-danger" style="font-size: 13px;">
            <strong>Integrity check found {{ count($integrity['problems']) }} problem(s).</strong>
            <ul class="mb-0 mt-1">
                @foreach($integrity['problems'] as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif

<div class="sacco-card mb-4">
    <form method="GET" action="{{ route('tenant.audit_log.index') }}" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label for="action" class="form-label" style="font-size: 12px;">Action</label>
            <select name="action" id="action" class="form-select form-select-sm">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ \App\Models\AuditLog::actionLabel($action) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="start_date" class="form-label" style="font-size: 12px;">From</label>
            <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="{{ $filters['start_date'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label for="end_date" class="form-label" style="font-size: 12px;">To</label>
            <input type="date" name="end_date" id="end_date" class="form-control form-control-sm" value="{{ $filters['end_date'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" name="overrides" id="overrides" value="1" @checked(request()->boolean('overrides'))>
                <label for="overrides" class="form-check-label" style="font-size: 13px;">Only decisions that went against the lending policy</label>
            </div>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-brand-primary btn-sm flex-fill">Filter</button>
            <a href="{{ route('tenant.audit_log.index') }}" class="btn btn-brand-outline btn-sm">Reset</a>
        </div>
    </form>
</div>

<div class="sacco-card">
    @if($logs->isEmpty())
        <p class="text-muted mb-0" style="font-size: 13px;">No audit entries match.</p>
    @else
        <div class="table-responsive">
            <table class="sacco-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        @php $details = $log->detailsArray(); @endphp
                        <tr>
                            <td class="font-monospace" style="font-size: 12px;">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                            <td>{{ $log->user_name ?? 'System' }}</td>
                            <td>
                                {{ $log->actionName() }}
                                @if($log->isOverride())
                                    <span class="badge" style="background: var(--color-risk-medium); color: #fff;">Override</span>
                                @endif
                            </td>
                            <td class="font-monospace" style="font-size: 12px;">
                                @php
                                    $link = match ($log->entity_type) {
                                        'Saving' => route('tenant.savings.show', $log->entity_id),
                                        'Repayment' => route('tenant.repayments.show', $log->entity_id),
                                        'Loan' => route('tenant.loans.show', $log->entity_id),
                                        'Member' => route('tenant.members.show', $log->entity_id),
                                        default => null,
                                    };
                                @endphp
                                @if($link)
                                    <a href="{{ $link }}" class="text-dark">{{ $details['reference'] ?? $log->entityName() }}</a>
                                @else
                                    {{ $details['member_number'] ?? $log->entityName() }}
                                @endif
                            </td>
                            <td style="font-size: 12px;">
                                @foreach($log->displayDetails() as $row)
                                    <div>
                                        <span class="text-muted">{{ $row['label'] }}:</span>
                                        {{ $row['value'] }}
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $logs->links() }}</div>
    @endif
</div>
@endsection
