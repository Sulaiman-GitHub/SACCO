@extends('layouts.app')

@section('title', 'Platform Operator Overview')
@section('page_title', 'Central Platform Administration')

@section('topbar_actions')
    <a href="{{ route('super_admin.saccos.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-lg me-1"></i> Onboard New SACCO
    </a>
@endsection

@section('content')
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Registered SACCOs</div>
            <div class="stat-value">{{ $totalSaccos }}</div>
            <div class="stat-desc">SACCOs registered on the platform</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Active Organizations</div>
            <div class="stat-value text-success" style="color: var(--color-risk-low) !important;">{{ $activeSaccos }}</div>
            <div class="stat-desc">SACCOs currently active</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-title">Platform Staff Accounts</div>
            <div class="stat-value">{{ $totalUsers }}</div>
            <div class="stat-desc">Administrators and loan officers</div>
        </div>
    </div>
</div>

<div class="sacco-card">
    <div class="sacco-card-header">
        <h2 style="font-size: 16px; margin: 0;">Recently Onboarded SACCOs</h2>
        <a href="{{ route('super_admin.saccos.index') }}" class="btn btn-brand-outline py-1 px-2" style="font-size: 12px;">
            View All SACCOs
        </a>
    </div>

    @if($recentSaccos->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-building-slash" style="font-size: 36px; color: var(--color-slate);"></i>
            <p class="mt-2 text-muted" style="font-size: 14px;">No SACCOs onboarded yet.</p>
            <a href="{{ route('super_admin.saccos.create') }}" class="btn btn-brand-primary mt-1">
                Onboard First SACCO
            </a>
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table">
                <thead>
                    <tr>
                        <th>SACCO Name</th>
                        <th>Tier</th>
                        <th>License No.</th>
                        <th>Staff Accounts</th>
                        <th>Status</th>
                        <th>Onboarded</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentSaccos as $sacco)
                        <tr>
                            <td>
                                <div class="fw-bold">{{ $sacco->name }}</div>
                                <div class="text-muted" style="font-size: 11px;">{{ $sacco->email ?? 'No email provided' }}</div>
                            </td>
                            <td><span class="badge bg-light text-dark border">{{ $sacco->tier }}</span></td>
                            <td><code>{{ $sacco->license_number ?? 'Pending' }}</code></td>
                            <td>{{ $sacco->users_count }} users</td>
                            <td>
                                @if($sacco->status === 'active')
                                    <span class="badge-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                @else
                                    <span class="badge-inactive"><i class="bi bi-pause-circle-fill me-1"></i>Suspended</span>
                                @endif
                            </td>
                            <td class="text-muted" style="font-size: 12px;">{{ $sacco->created_at->format('M d, Y') }}</td>
                            <td class="text-end">
                                <a href="{{ route('super_admin.saccos.show', $sacco) }}" class="btn btn-brand-outline py-1 px-2" style="font-size: 12px;">
                                    View SACCO
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
