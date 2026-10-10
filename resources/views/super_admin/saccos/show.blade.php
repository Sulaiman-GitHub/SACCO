@extends('layouts.app')

@section('title', $sacco->name)
@section('page_title', 'SACCO Profile')

@section('topbar_actions')
    <a href="{{ route('super_admin.saccos.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Back to SACCOs
    </a>
@endsection

@section('content')
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="sacco-card h-100">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">SACCO Details</h2>
                @if($sacco->status === 'active')
                    <span class="badge-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                @else
                    <span class="badge-inactive"><i class="bi bi-pause-circle-fill me-1"></i>Suspended</span>
                @endif
            </div>

            <div class="mb-3">
                <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">Legal Organization Name</div>
                <div class="fw-bold" style="font-size: 16px;">{{ $sacco->name }}</div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">Tier Classification</div>
                    <div><span class="badge bg-light text-dark border">{{ $sacco->tier }}</span></div>
                </div>
                <div class="col-6">
                    <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">License Number</div>
                    <div><code>{{ $sacco->license_number ?? 'Pending' }}</code></div>
                </div>
            </div>

            <div class="mb-3">
                <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">Email Contact</div>
                <div>{{ $sacco->email ?? 'Not specified' }}</div>
            </div>

            <div class="mb-3">
                <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">Phone Contact</div>
                <div>{{ $sacco->phone ?? 'Not specified' }}</div>
            </div>

            <div class="mb-4">
                <div class="text-muted" style="font-size: 11px; text-transform: uppercase;">Physical Address</div>
                <div>{{ $sacco->address ?? 'Not specified' }}</div>
            </div>

            <form method="POST" action="{{ route('super_admin.saccos.toggle_status', $sacco) }}">
                @csrf
                <button type="submit" class="btn w-100 {{ $sacco->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }}" style="font-size: 13px;">
                    {{ $sacco->status === 'active' ? 'Suspend SACCO Access' : 'Activate SACCO Access' }}
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="sacco-card h-100">
            <div class="sacco-card-header">
                <h2 style="font-size: 16px; margin: 0;">Staff Accounts ({{ $users->count() }})</h2>
            </div>

            @if($users->isEmpty())
                <div class="text-center py-4 text-muted">No staff accounts found.</div>
            @else
                <div class="table-responsive">
                    <table class="sacco-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td class="fw-bold">{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td><span class="badge-role">{{ ucfirst(str_replace('_', ' ', $user->role)) }}</span></td>
                                    <td>
                                        @if($user->status === 'active')
                                            <span class="badge-active">Active</span>
                                        @else
                                            <span class="badge-inactive">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="text-muted" style="font-size: 11px;">{{ $user->created_at->format('M d, Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
