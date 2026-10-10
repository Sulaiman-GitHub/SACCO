<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisburseLoanRequest;
use App\Http\Requests\RejectLoanRequest;
use App\Http\Requests\StoreLoanRequest;
use App\Models\LendingPolicy;
use App\Models\Loan;
use App\Models\Member;
use App\Services\CreditRiskScoringService;
use App\Services\LendingDecisionEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class TenantLoanController extends Controller
{
    /**
     * Display a listing of SACCO loans with filtering and portfolio metrics.
     */
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $status = $request->query('status');
        $memberId = $request->query('member_id');

        $query = Loan::query()->with(['member', 'creator']);

        if (! empty($search)) {
            $query->search($search);
        }

        if (! empty($status) && $status !== 'all') {
            $query->status($status);
        }

        if (! empty($memberId)) {
            $query->forMember((int) $memberId);
        }

        $loans = $query->latest('applied_at')->latest('id')->paginate(15)->withQueryString();

        // Refresh dynamic overdue/default status for paginated items
        foreach ($loans as $loan) {
            $loan->refreshStatus();
        }

        // SACCO Loan Portfolio Metrics
        $totalDisbursed = (float) Loan::whereIn('status', ['disbursed', 'overdue', 'defaulted', 'closed'])->sum('principal');
        $activeLoansCount = Loan::whereIn('status', ['disbursed', 'overdue'])->count();
        $overdueLoansCount = Loan::where('status', 'overdue')->count();
        $defaultedLoansCount = Loan::where('status', 'defaulted')->count();
        $pendingLoansCount = Loan::where('status', 'pending')->count();

        // Calculate total outstanding balance across all non-closed active/overdue/defaulted loans
        $activeLoans = Loan::whereIn('status', ['disbursed', 'overdue', 'defaulted'])->with('repayments')->get();
        $totalOutstandingBalance = round($activeLoans->sum(fn (Loan $l) => $l->remaining_balance), 2);

        return view('tenant.loans.index', compact(
            'loans',
            'search',
            'status',
            'totalDisbursed',
            'activeLoansCount',
            'overdueLoansCount',
            'defaultedLoansCount',
            'pendingLoansCount',
            'totalOutstandingBalance'
        ));
    }

    /**
     * Show the form for creating a new loan application.
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

        $nextReference = Loan::generateReference($saccoId);

        return view('tenant.loans.create', compact('members', 'selectedMember', 'nextReference'));
    }

    /**
     * Store a newly created loan application in storage.
     */
    public function store(StoreLoanRequest $request, CreditRiskScoringService $scoringService, LendingDecisionEngine $decisionEngine): RedirectResponse
    {
        $validated = $request->validated();
        $saccoId = (int) Auth::user()->sacco_id;

        $principal = (float) $validated['principal'];
        $rate = (float) $validated['interest_rate'];
        $termMonths = (int) $validated['term_months'];
        $calcType = $validated['interest_calculation_type'];

        $interest = Loan::calculateInterest($principal, $rate, $termMonths, $calcType);
        $totalAmount = round($principal + $interest, 2);

        $member = Member::find($validated['member_id']);
        $riskScore = null;
        $ruling = ['decision' => null, 'reason' => null];
        if ($member && ! $member->canBeScored()) {
            // Not being scored never blocks borrowing: the loan goes to an officer without a score
            $ruling['reason'] = $member->hasDeclinedScoring()
                ? 'Member declined credit scoring. No risk score was calculated; decide on the application manually.'
                : 'No consent record for this member. No risk score was calculated; record consent on the member profile, or decide on the application manually.';
        } elseif ($member) {
            $riskAssessment = $scoringService->scoreMember($member, $principal);
            $riskScore = $riskAssessment['risk_score'] ?? null;
            $ruling = $decisionEngine->decide(
                $riskAssessment,
                LendingPolicy::resolveFor($saccoId),
                ! empty($validated['guarantor_name'])
            );
        }

        $loan = DB::transaction(function () use ($validated, $saccoId, $principal, $rate, $termMonths, $calcType, $interest, $totalAmount, $riskScore, $ruling) {
            $reference = Loan::generateReference($saccoId);

            return Loan::create([
                'sacco_id' => $saccoId,
                'member_id' => $validated['member_id'],
                'loan_reference' => $reference,
                'principal' => $principal,
                'interest_rate' => $rate,
                'interest_calculation_type' => $calcType,
                'term_months' => $termMonths,
                'total_interest' => $interest,
                'total_amount' => $totalAmount,
                'purpose' => $validated['purpose'] ?? null,
                'status' => 'pending',
                'applied_at' => $validated['applied_at'],
                'guarantor_name' => $validated['guarantor_name'] ?? null,
                'guarantor_phone' => $validated['guarantor_phone'] ?? null,
                'guarantor_relationship' => $validated['guarantor_relationship'] ?? null,
                'risk_score' => $riskScore,
                'decision' => $ruling['decision'],
                'decision_reason' => $ruling['reason'],
                'created_by' => Auth::id(),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return redirect()->route('tenant.loans.show', $loan)
            ->with('success', "Loan application {$loan->loan_reference} submitted successfully for UGX ".number_format($loan->principal, 2).'.');
    }

    /**
     * Display a loan's details, repayment history, and lifecycle status.
     */
    public function show(Loan $loan, CreditRiskScoringService $scoringService): View
    {
        $this->ensureTenantOwnership($loan);

        $loan->refreshStatus();
        $loan->load(['member', 'creator', 'approver', 'disburser', 'rejecter', 'repayments.recorder']);

        $member = $loan->member;
        $savingsBalance = $member ? $member->savings_balance : 0.0;
        $loanToSavingsRatio = $savingsBalance > 0 ? round($loan->principal / $savingsBalance, 2) : 999.0;

        $riskAssessment = null;
        if ($member && $member->canBeScored()) {
            $riskAssessment = $scoringService->scoreMember($member, (float) $loan->principal);
            if ($loan->risk_score === null && isset($riskAssessment['risk_score'])) {
                $loan->update(['risk_score' => $riskAssessment['risk_score']]);
            }
        }

        return view('tenant.loans.show', compact('loan', 'member', 'savingsBalance', 'loanToSavingsRatio', 'riskAssessment'));
    }

    /**
     * Approve a pending loan.
     */
    public function approve(Request $request, Loan $loan): RedirectResponse
    {
        $this->ensureTenantOwnership($loan);

        $request->validate([
            'notes' => [$loan->isApprovalOverride() ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [
            'notes.required' => 'This loan went against the lending policy recommendation. Record the reason for overriding it in the approval remarks.',
        ]);

        try {
            $loan->approve(Auth::user(), $request->input('notes'));

            return redirect()->route('tenant.loans.show', $loan)
                ->with('success', "Loan {$loan->loan_reference} has been approved.");
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject a pending loan with required reason.
     */
    public function reject(RejectLoanRequest $request, Loan $loan): RedirectResponse
    {
        $this->ensureTenantOwnership($loan);

        try {
            $loan->reject(Auth::user(), $request->input('rejection_reason'));

            return redirect()->route('tenant.loans.show', $loan)
                ->with('success', "Loan {$loan->loan_reference} has been rejected.");
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Disburse an approved loan.
     */
    public function disburse(DisburseLoanRequest $request, Loan $loan): RedirectResponse
    {
        $this->ensureTenantOwnership($loan);

        $validated = $request->validated();

        try {
            $loan->disburse(
                Auth::user(),
                $validated['disbursement_method'],
                $validated['disbursement_reference'] ?? null,
                $validated['disbursement_date'],
                $validated['notes'] ?? null
            );

            return redirect()->route('tenant.loans.show', $loan)
                ->with('success', "Loan {$loan->loan_reference} disbursed successfully via ".ucwords(str_replace('_', ' ', $validated['disbursement_method'])).'. Due date set to '.$loan->due_date->format('M d, Y').'.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Ensure the loan belongs to authenticated user's SACCO.
     */
    private function ensureTenantOwnership(Loan $loan): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            return;
        }

        if ((int) $loan->sacco_id !== (int) $user->sacco_id) {
            abort(404, 'Loan record not found in your SACCO.');
        }
    }
}
