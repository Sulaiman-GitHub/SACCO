<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Saving;
use App\Models\User;
use App\Services\PortfolioReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TenantDashboardController extends Controller
{
    /**
     * Display tenant-scoped SACCO operational dashboard.
     */
    public function index(PortfolioReportService $reports): View
    {
        $user = Auth::user();
        $sacco = $user->sacco;

        // Scoped automatically to current SACCO via TenantScope on User, Member, and Saving models
        $staffCount = User::count();
        $loanOfficersCount = User::where('role', 'loan_officer')->count();
        $adminsCount = User::where('role', 'sacco_admin')->count();
        $membersCount = Member::count();
        $activeMembersCount = Member::where('status', 'active')->count();

        // Financial ledger metrics
        $totalDeposits = (float) Saving::deposits()->sum('amount');
        $totalWithdrawals = (float) Saving::withdrawals()->sum('amount');
        $portfolioBalance = round($totalDeposits - $totalWithdrawals, 2);

        $monthDeposits = (float) Saving::deposits()
            ->where('transaction_date', '>=', Carbon::now()->startOfMonth()->format('Y-m-d'))
            ->sum('amount');

        $recentMembers = Member::with('creator')->latest('id')->take(5)->get();
        $recentSavings = Saving::with('member')->latest('transaction_date')->latest('id')->take(5)->get();

        // Loan Portfolio Metrics (Module 4)
        $activeLoansCount = Loan::whereIn('status', ['disbursed', 'overdue'])->count();
        $delinquentLoansCount = Loan::whereIn('status', ['overdue', 'defaulted'])->count();
        $snapshot = $reports->snapshot();
        $outstandingLoanDebt = $snapshot['outstanding_balance'];
        $contributionTrend = $reports->contributionTrend();
        $defaultRateTrend = $reports->defaultRateTrend();
        $topRiskLoans = $reports->topRiskLoans();
        $recentLoans = Loan::with('member')->latest('applied_at')->latest('id')->take(5)->get();

        return view('tenant.dashboard', compact(
            'sacco',
            'user',
            'staffCount',
            'loanOfficersCount',
            'adminsCount',
            'membersCount',
            'activeMembersCount',
            'portfolioBalance',
            'monthDeposits',
            'recentMembers',
            'recentSavings',
            'activeLoansCount',
            'delinquentLoansCount',
            'outstandingLoanDebt',
            'snapshot',
            'contributionTrend',
            'defaultRateTrend',
            'topRiskLoans',
            'recentLoans'
        ));
    }
}
