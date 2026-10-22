@extends('layouts.app')

@section('title', 'SACCO Staff & Users')
@section('page_title', 'Staff Management')

@section('topbar_actions')
    <a href="{{ route('tenant.staff.create') }}" class="btn btn-brand-primary">
        <i class="bi bi-person-plus me-1"></i> Add Staff Member
    </a>
@endsection

@section('content')
<div class="sacco-card">
    <div class="sacco-card-header">
        <div>
            <h2 style="font-size: 16px; margin: 0;">Authorized Personnel for {{ Auth::user()->sacco->name }}</h2>
            <div class="text-muted" style="font-size: 12px; margin-top: 2px;">
                Only staff of your SACCO are listed here.
            </div>
        </div>
    </div>

    @if($staff->isEmpty())
        <div class="text-center py-4 text-muted">No staff members found.</div>
    @else
        <div class="table-responsive">
            <table class="sacco-table table-stack">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Assigned Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($staff as $member)
                        <tr>
                            <td data-label="Name">
                                <div class="fw-bold">{{ $member->name }}</div>
                                @if($member->id === Auth::id())
                                    <span class="badge bg-secondary" style="font-size: 10px;">You</span>
                                @endif
                            </td>
                            <td data-label="Email">{{ $member->email }}</td>
                            <td data-label="Phone">{{ $member->phone ?? 'N/A' }}</td>
                            <td data-label="Assigned Role">
                                @if($member->role === 'sacco_admin')
                                    <span class="badge bg-dark">SACCO Admin</span>
                                @else
                                    <span class="badge bg-info text-dark">Loan Officer</span>
                                @endif
                            </td>
                            <td data-label="Status">
                                @if($member->status === 'active')
                                    <span class="badge-active"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                @else
                                    <span class="badge-inactive"><i class="bi bi-pause-circle-fill me-1"></i>Inactive</span>
                                @endif
                            </td>
                            <td data-label="Created" class="text-muted" style="font-size: 11px;">{{ $member->created_at->format('M d, Y') }}</td>
                            <td data-label="" class="text-end">
                                @if($member->id !== Auth::id())
                                    <form method="POST" action="{{ route('tenant.staff.toggle_status', $member) }}" class="d-inline" onsubmit="return confirm('Toggle status for this staff member?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $member->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }} py-1 px-2" style="font-size: 12px;">
                                            {{ $member->status === 'active' ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="text-muted" style="font-size: 12px;">Current Session</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $staff->links() }}
        </div>
    @endif
</div>
@endsection
