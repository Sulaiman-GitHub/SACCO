<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only list of online payments for the staff member's own SACCO.
 */
class TenantPaymentController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $purpose = $request->query('purpose');
        $search = trim((string) $request->query('search'));

        $query = PaymentTransaction::query()->with(['member', 'loan']);

        if ($status === 'pending') {
            $query->whereIn('status', PaymentTransaction::OPEN_STATUSES);
        } elseif (in_array($status, ['successful', 'failed', 'cancelled', 'expired'], true)) {
            $query->where('status', $status);
        }

        if (in_array($purpose, [PaymentTransaction::PURPOSE_DEPOSIT, PaymentTransaction::PURPOSE_REPAYMENT], true)) {
            $query->where('purpose', $purpose);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('tx_ref', 'like', "%{$search}%")
                    ->orWhere('provider_reference', 'like', "%{$search}%")
                    ->orWhereHas('member', fn ($m) => $m->where('full_name', 'like', "%{$search}%")->orWhere('member_number', 'like', "%{$search}%"));
            });
        }

        return view('tenant.payments.index', [
            'payments' => $query->latest('id')->paginate(20)->withQueryString(),
            'status' => $status,
            'purpose' => $purpose,
            'search' => $search,
        ]);
    }
}
