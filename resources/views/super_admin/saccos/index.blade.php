@extends('layouts.app')

@section('title', 'Registered SACCOs')
@section('page_title', 'Registered SACCOs')

@section('topbar_actions')
    <a href="{{ route('super_admin.saccos.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-plus-lg me-1"></i> Onboard New SACCO
    </a>
@endsection

@section('content')
<div class="sacco-card mb-4 p-3">
    <form method="GET" action="{{ route('super_admin.saccos.index') }}" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, license number, or email..." value="{{ request('search') }}">
            </div>
        </div>
        <div class="col-md-3">
            <select name="tier" class="form-select">
                <option value="">All Regulatory Tiers</option>
                <option value="Tier 1" {{ request('tier') == 'Tier 1' ? 'selected' : '' }}>Tier 1 (Deposit-taking large)</option>
                <option value="Tier 2" {{ request('tier') == 'Tier 2' ? 'selected' : '' }}>Tier 2 (Deposit-taking medium)</option>
                <option value="Tier 3" {{ request('tier') == 'Tier 3' ? 'selected' : '' }}>Tier 3 (Small credit institutions)</option>
                <option value="Tier 4" {{ request('tier') == 'Tier 4' ? 'selected' : '' }}>Tier 4 (Community non-deposit taking)</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                <option value="suspended" {{ request('status') == 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-brand-dark w-100">Filter</button>
            @if(request()->hasAny(['search', 'tier', 'status']))
                <a href="{{ route('super_admin.saccos.index') }}" class="btn btn-brand-outline" title="Clear Filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            @endif
        </div>
    </form>
</div>

<div class="sacco-card">
    @if($saccos->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-search" style="font-size: 32px; color: var(--color-slate);"></i>
            <p class="mt-2 text-muted" style="font-size: 14px;">No matching SACCOs found.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="sacco-table table-stack">
                <thead>
                    <tr>
                        <th>SACCO Name & Contact</th>
                        <th>Tier</th>
                        <th>License Number</th>
                        <th>Staff Accounts</th>
                        <th>Status</th>
                        <th>Registered Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($saccos as $sacco)
                        <tr>
                            <td data-label="SACCO Name & Contact">
                                <div class="fw-bold" style="font-size: 14px;">{{ $sacco->name }}</div>
                                <div class="text-muted" style="font-size: 12px;">
                                    <i class="bi bi-envelope me-1"></i>{{ $sacco->email ?? 'N/A' }}
                                    @if($sacco->phone) &bull; <i class="bi bi-telephone me-1"></i>{{ $sacco->phone }} @endif
                                </div>
                            </td>
                            <td data-label="Tier"><span class="badge bg-light text-dark border">{{ $sacco->tier }}</span></td>
                            <td data-label="License Number"><code>{{ $sacco->license_number ?? 'None' }}</code></td>
                            <td data-label="Staff Accounts">
                                <span class="badge-role">{{ $sacco->users_count }} active staff</span>
                            </td>
                            <td data-label="Status">
                                @if($sacco->status === 'active')
                                    <span class="badge-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                @else
                                    <span class="badge-inactive"><i class="bi bi-pause-circle-fill me-1"></i>Suspended</span>
                                @endif
                            </td>
                            <td data-label="Registered Date" class="text-muted" style="font-size: 12px;">{{ $sacco->created_at->format('M d, Y') }}</td>
                            <td data-label="" class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a href="{{ route('super_admin.saccos.show', $sacco) }}" class="btn btn-brand-outline py-1 px-2" style="font-size: 12px;">
                                        View Details
                                    </a>
                                    <form method="POST" action="{{ route('super_admin.saccos.toggle_status', $sacco) }}" onsubmit="return confirm('Are you sure you want to change this SACCO status?');" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $sacco->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }} py-1 px-2" style="font-size: 12px;">
                                            {{ $sacco->status === 'active' ? 'Suspend' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $saccos->links() }}
        </div>
    @endif
</div>
@endsection
