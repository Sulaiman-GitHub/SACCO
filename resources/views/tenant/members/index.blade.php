@extends('layouts.app')

@section('title', 'Members Directory')
@section('page_title', 'Member Management')

@section('topbar_actions')
    <a href="{{ route('tenant.members.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-person-plus me-1"></i> Register New Member
    </a>
@endsection

@section('content')
{{-- Member Statistics --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Total Members</div>
            <div class="stat-value">{{ number_format($totalMembers) }}</div>
            <div class="stat-desc">All registered members</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Active Members</div>
            <div class="stat-value" style="color: var(--color-risk-low);">{{ number_format($activeMembers) }}</div>
            <div class="stat-desc">Eligible for SACCO services</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">Inactive / Suspended</div>
            <div class="stat-value" style="color: var(--color-risk-high);">{{ number_format($inactiveMembers + $suspendedMembers) }}</div>
            <div class="stat-desc">Dormant or restricted</div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-title">New This Month</div>
            <div class="stat-value" style="color: var(--color-teal);">{{ number_format($newThisMonth) }}</div>
            <div class="stat-desc">Onboarded in current period</div>
        </div>
    </div>
</div>

{{-- Search and Filter Bar --}}
<div class="sacco-card mb-4">
    <form method="GET" action="{{ route('tenant.members.index') }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Search Member</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="search" name="search" class="form-control border-start-0" placeholder="Search by name, phone, member no, or NIN..." value="{{ request('search') }}">
                </div>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label text-muted" style="font-size: 11px; text-transform: uppercase; font-weight: 600;">Status</label>
                <select id="status" name="status" class="form-select">
                    <option value="all" {{ request('status') === 'all' || !request('status') ? 'selected' : '' }}>All Statuses</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-brand-dark flex-grow-1">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                @if(request('search') || (request('status') && request('status') !== 'all'))
                    <a href="{{ route('tenant.members.index') }}" class="btn btn-brand-outline" title="Reset Filters">
                        <i class="bi bi-x-circle me-1"></i> Reset
                    </a>
                @endif
            </div>
        </div>
    </form>
</div>

{{-- Members Table --}}
<div class="sacco-card">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">Registered Members</h2>
            <div style="font-size: 12px; color: var(--color-slate);">
                Showing {{ $members->firstItem() ?? 0 }} - {{ $members->lastItem() ?? 0 }} of {{ $members->total() }} members
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border">
                <i class="bi bi-shield-lock me-1 text-success"></i> ID numbers masked for privacy
            </span>
        </div>
    </div>

    @if($members->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-person-x text-muted" style="font-size: 40px;"></i>
            <h4 class="mt-3 mb-1" style="font-size: 16px;">No members found</h4>
            <p class="text-muted mb-3" style="font-size: 13px;">
                @if(request('search') || request('status'))
                    No member records match your search criteria. Try clearing filters.
                @else
                    Get started by onboarding your first SACCO member.
                @endif
            </p>
            @if(request('search') || request('status'))
                <a href="{{ route('tenant.members.index') }}" class="btn btn-sm btn-brand-outline">Clear Search</a>
            @else
                <a href="{{ route('tenant.members.create') }}" class="btn btn-sm btn-brand-primary">
                    <i class="bi bi-person-plus me-1"></i> Register Member
                </a>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table table-stack">
                <thead>
                    <tr>
                        <th>Member No.</th>
                        <th>Full Name</th>
                        <th>Contact</th>
                        <th>National ID (Protected)</th>
                        <th>Tenure</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($members as $member)
                        <tr>
                            <td data-label="Member No.">
                                <a href="{{ route('tenant.members.show', $member) }}" class="fw-bold text-decoration-none text-dark">
                                    {{ $member->member_number }}
                                </a>
                            </td>
                            <td data-label="Full Name">
                                <div class="fw-semibold text-dark">{{ $member->full_name }}</div>
                                @if($member->occupation)
                                    <div style="font-size: 11px; color: var(--color-slate);">{{ $member->occupation }}</div>
                                @endif
                            </td>
                            <td data-label="Contact">
                                <div><code>{{ $member->phone }}</code></div>
                                @if($member->email)
                                    <div style="font-size: 11px; color: var(--color-slate);">{{ $member->email }}</div>
                                @endif
                            </td>
                            <td data-label="National ID (Protected)">
                                <div class="d-flex align-items-center gap-1">
                                    <i class="bi bi-shield-check text-success" style="font-size: 13px;" title="Masked to protect privacy"></i>
                                    <span class="font-monospace" style="font-size: 12px; color: var(--color-black);">
                                        {{ $member->national_id_masked ?? 'Protected' }}
                                    </span>
                                </div>
                            </td>
                            <td data-label="Tenure">
                                <span class="badge bg-light text-dark border" style="font-size: 11px;">
                                    {{ $member->tenure_months }} mo
                                </span>
                                <div style="font-size: 11px; color: var(--color-slate); margin-top: 2px;">
                                    Joined {{ $member->join_date?->format('M Y') }}
                                </div>
                            </td>
                            <td data-label="Status">
                                @if($member->status === 'active')
                                    <span class="badge-active">Active</span>
                                @elseif($member->status === 'suspended')
                                    <span class="badge-inactive">Suspended</span>
                                @else
                                    <span class="badge-inactive">Inactive</span>
                                @endif
                            </td>
                            <td data-label="" class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a href="{{ route('tenant.members.show', $member) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="View Profile">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('tenant.members.edit', $member) }}" class="btn btn-sm btn-brand-outline p-1 px-2" title="Edit Member">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @if(Auth::user()->isSaccoAdmin())
                                        <form method="POST" action="{{ route('tenant.members.toggle_status', $member) }}" class="d-inline m-0" onsubmit="return confirm('Change status for {{ $member->full_name }}?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm {{ $member->isActive() ? 'btn-outline-danger' : 'btn-outline-success' }} p-1 px-2" title="{{ $member->isActive() ? 'Deactivate Member' : 'Activate Member' }}">
                                                <i class="bi {{ $member->isActive() ? 'bi-person-dash' : 'bi-person-check' }}"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $members->links() }}
        </div>
    @endif
</div>
@endsection
