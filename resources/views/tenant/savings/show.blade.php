@extends('layouts.app')

@section('title', 'Receipt ' . $saving->transaction_reference)
@section('page_title', 'Transaction Receipt')

@section('topbar_actions')
    <a href="{{ route('tenant.savings.index') }}" class="btn btn-brand-outline">
        <i class="bi bi-arrow-left me-1"></i> Savings Ledger
    </a>
    <a href="{{ route('tenant.members.savings_statement', $saving->member) }}" class="btn btn-brand-outline">
        <i class="bi bi-journal-text me-1"></i> Member Statement
    </a>
    <button onclick="window.print()" class="btn btn-brand-dark">
        <i class="bi bi-printer me-1"></i> Print Receipt
    </button>
@endsection

@section('styles')
<style>
@media print {
    .app-sidebar, .app-topbar, .btn, .alert, .no-print {
        display: none !important;
    }
    .app-shell, .app-main, .app-content {
        padding: 0 !important;
        margin: 0 !important;
        background: #FFFFFF !important;
    }
    .receipt-container {
        border: 1px solid #000000 !important;
        box-shadow: none !important;
        margin: 0 auto !important;
        max-width: 100% !important;
    }
}
</style>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        {{-- Printable Receipt Box --}}
        <div class="sacco-card receipt-container p-4 p-md-5">
            {{-- Header with SACCO Details --}}
            <div class="text-center pb-4 mb-4 border-bottom">
                <div class="brand-title fs-4 fw-bold text-dark">{{ $saving->sacco->name }}</div>
                <div class="text-muted" style="font-size: 12px;">
                    License No: <strong>{{ $saving->sacco->license_number ?? 'UMRA Registered' }}</strong> &bull;
                    Classification: <strong>{{ $saving->sacco->tier }}</strong>
                </div>
                @if($saving->sacco->address || $saving->sacco->phone)
                    <div class="text-muted mt-1" style="font-size: 11px;">
                        {{ $saving->sacco->address }} &bull; {{ $saving->sacco->phone }}
                    </div>
                @endif
                <div class="mt-3">
                    <span class="badge {{ $saving->isDeposit() ? 'badge-active' : 'badge-inactive' }} px-3 py-2 fs-6">
                        OFFICIAL {{ strtoupper($saving->type) }} RECEIPT
                    </span>
                </div>
            </div>

            {{-- Receipt Meta --}}
            <div class="row g-3 mb-4" style="font-size: 13px;">
                <div class="col-6">
                    <span class="text-muted">Receipt Ref:</span>
                    <div class="fw-bold font-monospace fs-6">{{ $saving->transaction_reference }}</div>
                </div>
                <div class="col-6 text-end">
                    <span class="text-muted">Transaction Date:</span>
                    <div class="fw-bold">{{ $saving->transaction_date->format('d F Y') }}</div>
                </div>
            </div>

            {{-- Member Info Banner --}}
            <div class="p-3 bg-light rounded border mb-4">
                <div class="row g-2 align-items-center" style="font-size: 13px;">
                    <div class="col-sm-6">
                        <span class="text-muted" style="font-size: 11px; text-transform: uppercase;">Member Name:</span>
                        <div class="fw-bold text-dark fs-6">{{ $saving->member->full_name }}</div>
                    </div>
                    <div class="col-sm-3">
                        <span class="text-muted" style="font-size: 11px; text-transform: uppercase;">Member ID:</span>
                        <div class="fw-semibold"><code>{{ $saving->member->member_number }}</code></div>
                    </div>
                    <div class="col-sm-3 text-sm-end">
                        <span class="text-muted" style="font-size: 11px; text-transform: uppercase;">Phone Line:</span>
                        <div class="fw-semibold">{{ $saving->member->phone }}</div>
                    </div>
                </div>
            </div>

            {{-- Amount Breakdown Table --}}
            <div class="table-responsive mb-4">
                <table class="table table-bordered mb-0">
                    <thead class="table-light">
                        <tr style="font-size: 12px; text-transform: uppercase;">
                            <th>Description</th>
                            <th>Channel & Reference</th>
                            <th class="text-end">Amount (UGX)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark">
                                    {{ $saving->isDeposit() ? 'Member Savings Contribution' : 'Member Savings Withdrawal' }}
                                </div>
                                <div class="text-muted" style="font-size: 12px;">
                                    {{ $saving->notes ?? 'Standard ledger transaction' }}
                                </div>
                            </td>
                            <td>
                                <div>{{ strtoupper(str_replace('_', ' ', $saving->payment_method)) }}</div>
                                @if($saving->payment_reference)
                                    <div class="text-muted font-monospace" style="font-size: 11px;">
                                        Ref: {{ $saving->payment_reference }}
                                    </div>
                                @endif
                            </td>
                            <td class="text-end font-monospace fw-bold fs-5" style="color: {{ $saving->isDeposit() ? 'var(--color-risk-low)' : 'var(--color-risk-high)' }};">
                                {{ number_format($saving->amount, 2) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="2" class="text-end">Total Transaction Amount:</th>
                            <th class="text-end font-monospace fs-5">
                                UGX {{ number_format($saving->amount, 2) }}
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Member Current Balance Summary --}}
            <div class="p-3 border rounded mb-4" style="background: #F8FBF9; border-color: rgba(31, 174, 92, 0.4) !important;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 13px;">Member's Computed Savings Balance</div>
                        <div class="text-muted" style="font-size: 11px;">Reflects all deposits less withdrawals to date</div>
                    </div>
                    <div class="font-monospace fw-bold fs-5" style="color: var(--color-teal);">
                        UGX {{ number_format($saving->member->savings_balance, 2) }}
                    </div>
                </div>
            </div>

            {{-- Signatures / Audit Footer --}}
            <div class="row pt-4 mt-2 border-top text-muted" style="font-size: 12px;">
                <div class="col-6">
                    <div>Recorded by: <strong>{{ $saving->recorder->name ?? 'System' }}</strong></div>
                    <div style="font-size: 11px;">Timestamp: {{ $saving->created_at->format('d M Y, H:i:s') }}</div>
                </div>
                <div class="col-6 text-end">
                    <div class="mt-4 pt-3 border-top d-inline-block text-center" style="width: 180px; max-width: 100%;">
                        Authorized Signature
                    </div>
                </div>
            </div>
        </div>

        {{-- Quick Post-Action Buttons --}}
        <div class="d-flex flex-wrap gap-2 justify-content-between no-print mt-3 mb-5">
            <a href="{{ route('tenant.savings.create') }}" class="btn btn-brand-primary">
                <i class="bi bi-plus-circle me-1"></i> Record Another Transaction
            </a>
            <a href="{{ route('tenant.members.show', $saving->member) }}" class="btn btn-brand-outline">
                <i class="bi bi-person me-1"></i> Return to {{ $saving->member->full_name }}
            </a>
        </div>
    </div>
</div>
@endsection
