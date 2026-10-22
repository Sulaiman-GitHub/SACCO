<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepaymentRequest;
use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantRepaymentController extends Controller
{
    /**
     * Display SACCO loan repayments ledger with search and financial summaries.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $paymentMethod = $request->query('payment_method');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $loanId = $request->query('loan_id');
        $memberId = $request->query('member_id');

        $query = Repayment::query()->with(['loan', 'member', 'recorder']);

        if (! empty($search)) {
            $query->search($search);
        }

        if (! empty($paymentMethod) && $paymentMethod !== 'all') {
            $query->paymentMethod($paymentMethod);
        }

        if (! empty($startDate) || ! empty($endDate)) {
            $query->dateRange($startDate, $endDate);
        }

        if (! empty($loanId)) {
            $query->forLoan((int) $loanId);
        }

        if (! empty($memberId)) {
            $query->forMember((int) $memberId);
        }

        $repayments = $query->latest('repayment_date')->latest('id')->paginate(15)->withQueryString();

        // Repayment Portfolio Summaries
        $totalRepayments = (float) Repayment::sum('amount');
        $monthStart = Carbon::now()->startOfMonth()->format('Y-m-d');
        $monthRepayments = (float) Repayment::where('repayment_date', '>=', $monthStart)->sum('amount');
        $repaymentCount = Repayment::count();

        return view('tenant.repayments.index', compact(
            'repayments',
            'search',
            'paymentMethod',
            'startDate',
            'endDate',
            'totalRepayments',
            'monthRepayments',
            'repaymentCount'
        ));
    }

    /**
     * Show the form for recording a loan repayment installment.
     */
    public function create(Request $request): View
    {
        $saccoId = (int) Auth::user()->sacco_id;
        $selectedLoanId = $request->query('loan_id');
        $selectedLoan = null;

        if ($selectedLoanId) {
            $selectedLoan = Loan::with('member')->find($selectedLoanId);
            if ($selectedLoan && (int) $selectedLoan->sacco_id !== $saccoId) {
                $selectedLoan = null;
            }
        }

        $loans = Loan::whereIn('status', ['disbursed', 'overdue', 'defaulted'])
            ->with(['member', 'repayments'])
            ->get()
            ->filter(fn (Loan $l) => $l->remaining_balance > 0)
            ->values();

        $nextReference = Repayment::previewReference($saccoId);

        return view('tenant.repayments.create', compact('loans', 'selectedLoan', 'nextReference'));
    }

    /**
     * Store an append-only repayment installment.
     */
    public function store(StoreRepaymentRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $saccoId = (int) Auth::user()->sacco_id;

        $repayment = Repayment::recordLocked($validated, $saccoId, Auth::id());

        $loan = $repayment->loan;
        $statusNote = $loan->isClosed() ? ' The loan is now FULLY REPAID and closed!' : ' Remaining balance: UGX '.number_format($loan->remaining_balance, 2);

        return redirect()->route('tenant.repayments.show', $repayment)
            ->with('success', 'Repayment of UGX '.number_format($repayment->amount, 2)." recorded successfully for {$repayment->member->full_name}. Ref: {$repayment->repayment_reference}.{$statusNote}");
    }

    /**
     * Display a transaction receipt for a loan repayment.
     */
    public function show(Repayment $repayment): View
    {
        $this->ensureTenantOwnership($repayment);

        $repayment->load(['loan.member', 'member', 'recorder', 'sacco']);

        return view('tenant.repayments.show', compact('repayment'));
    }

    /**
     * Ensure the repayment belongs to authenticated user's SACCO.
     */
    private function ensureTenantOwnership(Repayment $repayment): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $repayment->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Repayment record not found in your SACCO.');
        }
    }
}
