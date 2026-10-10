<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSavingsRequest;
use App\Models\Member;
use App\Models\Saving;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantSavingsController extends Controller
{
    /**
     * Display SACCO savings ledger with search and financial summaries.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $type = $request->query('type');
        $paymentMethod = $request->query('payment_method');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $memberId = $request->query('member_id');

        $query = Saving::query()->with(['member', 'recorder']);

        if (! empty($search)) {
            $query->search($search);
        }

        if (! empty($type) && $type !== 'all') {
            $query->where('type', $type);
        }

        if (! empty($paymentMethod) && $paymentMethod !== 'all') {
            $query->paymentMethod($paymentMethod);
        }

        if (! empty($startDate) || ! empty($endDate)) {
            $query->dateRange($startDate, $endDate);
        }

        if (! empty($memberId)) {
            $query->forMember((int) $memberId);
        }

        $savings = $query->latest('transaction_date')->latest('id')->paginate(15)->withQueryString();

        // Overall Financial Metrics for this SACCO Instance
        $totalDeposits = (float) Saving::deposits()->sum('amount');
        $totalWithdrawals = (float) Saving::withdrawals()->sum('amount');
        $portfolioBalance = round($totalDeposits - $totalWithdrawals, 2);

        $monthStart = Carbon::now()->startOfMonth()->format('Y-m-d');
        $monthDeposits = (float) Saving::deposits()->where('transaction_date', '>=', $monthStart)->sum('amount');
        $monthWithdrawals = (float) Saving::withdrawals()->where('transaction_date', '>=', $monthStart)->sum('amount');
        $transactionCount = Saving::count();

        return view('tenant.savings.index', compact(
            'savings',
            'search',
            'type',
            'paymentMethod',
            'startDate',
            'endDate',
            'totalDeposits',
            'totalWithdrawals',
            'portfolioBalance',
            'monthDeposits',
            'monthWithdrawals',
            'transactionCount'
        ));
    }

    /**
     * Show the form for recording a deposit or withdrawal.
     */
    public function create(Request $request): View
    {
        $saccoId = (int) Auth::user()->sacco_id;
        $selectedMemberId = $request->query('member_id');
        $selectedMember = null;

        if ($selectedMemberId) {
            $selectedMember = Member::find($selectedMemberId);
            if ($selectedMember && (int) $selectedMember->sacco_id !== $saccoId) {
                $selectedMember = null;
            }
        }

        $members = Member::where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'member_number', 'phone']);

        $nextReference = Saving::generateReference($saccoId);

        return view('tenant.savings.create', compact('members', 'selectedMember', 'nextReference'));
    }

    /**
     * Store an append-only savings ledger transaction.
     */
    public function store(StoreSavingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $saccoId = (int) Auth::user()->sacco_id;

        $saving = DB::transaction(function () use ($validated, $saccoId) {
            $reference = Saving::generateReference($saccoId);

            return Saving::create([
                'sacco_id' => $saccoId,
                'member_id' => $validated['member_id'],
                'transaction_reference' => $reference,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'transaction_date' => $validated['transaction_date'],
                'recorded_by' => Auth::id(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        $actionLabel = $saving->isDeposit() ? 'Deposit' : 'Withdrawal';

        return redirect()->route('tenant.savings.show', $saving)
            ->with('success', "{$actionLabel} of UGX ".number_format($saving->amount, 2)." recorded successfully for {$saving->member->full_name}. Ref: {$saving->transaction_reference}");
    }

    /**
     * Display a transaction receipt.
     */
    public function show(Saving $saving): View
    {
        $this->ensureTenantOwnership($saving);

        $saving->load(['member', 'recorder', 'sacco']);

        return view('tenant.savings.show', compact('saving'));
    }

    /**
     * Display a member's complete chronological savings statement / passbook.
     */
    public function memberStatement(Member $member): View
    {
        $this->ensureMemberTenantOwnership($member);

        $transactions = Saving::forMember($member->id)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->with('recorder')
            ->get();

        // Calculate chronological running balance
        $runningBalance = 0;
        foreach ($transactions as $txn) {
            if ($txn->isDeposit()) {
                $runningBalance += (float) $txn->amount;
            } else {
                $runningBalance -= (float) $txn->amount;
            }
            $txn->computed_balance = $runningBalance;
        }

        // Reverse for display so newest appears at top, but with calculated historical balances intact
        $statementTransactions = $transactions->reverse();

        return view('tenant.savings.statement', compact('member', 'statementTransactions', 'runningBalance'));
    }

    /**
     * Ensure the transaction belongs to authenticated user's SACCO.
     */
    private function ensureTenantOwnership(Saving $saving): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $saving->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Savings record not found in your SACCO.');
        }
    }

    /**
     * Ensure member belongs to authenticated user's SACCO.
     */
    private function ensureMemberTenantOwnership(Member $member): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $member->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Member record not found in your SACCO.');
        }
    }
}
