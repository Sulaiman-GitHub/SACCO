<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantAuditLogController extends Controller
{
    /**
     * Read-only audit trail for the SACCO, with filters.
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:50'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'overrides' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $query = AuditLog::query()
            ->action($filters['action'] ?? null)
            ->entityType($filters['entity_type'] ?? null)
            ->dateRange($filters['start_date'] ?? null, $filters['end_date'] ?? null);

        if ($request->boolean('overrides')) {
            $query->whereIn('action', ['loan.approved', 'loan.rejected'])->where('details', 'like', '%"override":true%');
        }

        $logs = $query->orderByDesc('id')->paginate(25)->withQueryString();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('tenant.audit_log.index', compact('logs', 'actions', 'filters'));
    }

    /**
     * Verify the integrity of the SACCO's audit chain and ledger.
     */
    public function verify(AuditTrail $auditTrail): RedirectResponse
    {
        $result = $auditTrail->verify((int) Auth::user()->sacco_id);

        return redirect()->route('tenant.audit_log.index')->with('integrity', $result);
    }
}
