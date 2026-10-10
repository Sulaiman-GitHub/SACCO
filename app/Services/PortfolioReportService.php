<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Saving;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only portfolio metrics for the dashboard and reports.
 *
 * All queries go through tenant-scoped models, so figures always reflect the logged-in SACCO.
 * Month grouping happens in PHP to stay portable between SQLite (tests) and MySQL (production).
 */
class PortfolioReportService
{
    /**
     * Loan statuses that still carry an outstanding balance.
     *
     * @var list<string>
     */
    private const OUTSTANDING_STATUSES = ['disbursed', 'overdue', 'defaulted'];

    /**
     * Operational snapshot: today's savings, outstanding loans, arrears and portfolio health.
     *
     * @return array{
     *     deposits_today: float,
     *     withdrawals_today: float,
     *     outstanding_balance: float,
     *     active_loans: int,
     *     pending_loans: int,
     *     delinquent_loans: int,
     *     defaulted_loans: int,
     *     at_risk_balance: float,
     *     portfolio_at_risk_pct: float,
     *     at_risk_members: Collection<int, array{member_id: int, name: string, member_number: string, balance: float, days_overdue: int, status: string, loan_id: int}>
     * }
     */
    public function snapshot(): array
    {
        $today = Carbon::today()->format('Y-m-d');

        $loans = Loan::whereIn('status', self::OUTSTANDING_STATUSES)->with(['member', 'repayments'])->get();
        $loans->each->refreshStatus();
        $loans = $loans->whereIn('status', self::OUTSTANDING_STATUSES);

        $outstanding = round($loans->sum(fn (Loan $loan) => $loan->remaining_balance), 2);
        $delinquent = $loans->whereIn('status', ['overdue', 'defaulted']);
        $atRiskBalance = round($delinquent->sum(fn (Loan $loan) => $loan->remaining_balance), 2);

        return [
            'deposits_today' => (float) Saving::deposits()->whereDate('transaction_date', $today)->sum('amount'),
            'withdrawals_today' => (float) Saving::withdrawals()->whereDate('transaction_date', $today)->sum('amount'),
            'outstanding_balance' => $outstanding,
            'active_loans' => $loans->whereIn('status', ['disbursed', 'overdue'])->count(),
            'pending_loans' => Loan::where('status', 'pending')->count(),
            'delinquent_loans' => $delinquent->count(),
            'defaulted_loans' => $loans->where('status', 'defaulted')->count(),
            'at_risk_balance' => $atRiskBalance,
            'portfolio_at_risk_pct' => $outstanding > 0 ? round($atRiskBalance / $outstanding * 100, 1) : 0.0,
            'at_risk_members' => $delinquent
                ->sortByDesc(fn (Loan $loan) => $loan->days_overdue)
                ->map(fn (Loan $loan) => [
                    'member_id' => $loan->member_id,
                    'name' => $loan->member->full_name ?? 'Unknown member',
                    'member_number' => $loan->member->member_number ?? '',
                    'balance' => $loan->remaining_balance,
                    'days_overdue' => $loan->days_overdue,
                    'status' => $loan->status,
                    'loan_id' => $loan->id,
                ])
                ->values(),
        ];
    }

    /**
     * Monthly deposits and withdrawals for the most recent months, oldest first.
     *
     * @return array{labels: list<string>, deposits: list<float>, withdrawals: list<float>}
     */
    public function contributionTrend(int $months = 6): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $rows = Saving::where('transaction_date', '>=', $start->format('Y-m-d'))
            ->get(['type', 'amount', 'transaction_date']);

        $labels = [];
        $deposits = [];
        $withdrawals = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $inMonth = $rows->filter(fn (Saving $row) => $row->transaction_date->isSameMonth($month));

            $labels[] = $month->format('M Y');
            $deposits[] = round((float) $inMonth->where('type', 'deposit')->sum('amount'), 2);
            $withdrawals[] = round((float) $inMonth->where('type', 'withdrawal')->sum('amount'), 2);
        }

        return compact('labels', 'deposits', 'withdrawals');
    }

    /**
     * Share of each month's disbursed loans that have since defaulted, oldest first.
     * Months with no disbursements are null so the chart shows a gap, not a false 0%.
     *
     * @return array{labels: list<string>, rates: list<float|null>, disbursed: list<int>}
     */
    public function defaultRateTrend(int $months = 6): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        $loans = Loan::whereNotNull('disbursed_at')
            ->where('disbursed_at', '>=', $start)
            ->get(['status', 'disbursed_at']);

        $labels = [];
        $rates = [];
        $disbursed = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $cohort = $loans->filter(fn (Loan $loan) => $loan->disbursed_at->isSameMonth($month));

            $labels[] = $month->format('M Y');
            $disbursed[] = $cohort->count();
            $rates[] = $cohort->isEmpty() ? null : round($cohort->where('status', 'defaulted')->count() / $cohort->count() * 100, 1);
        }

        return compact('labels', 'rates', 'disbursed');
    }

    /**
     * Open loan applications and loans with the highest ML risk scores.
     *
     * @return Collection<int, Loan>
     */
    public function topRiskLoans(int $limit = 5): Collection
    {
        return Loan::whereIn('status', ['pending', 'approved', 'disbursed', 'overdue'])
            ->whereNotNull('risk_score')
            ->with('member')
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->get();
    }

    /**
     * Savings ledger rows for export.
     *
     * @return iterable<int, list<string|float>>
     */
    public function savingsExportRows(?string $startDate, ?string $endDate): iterable
    {
        $query = Saving::query()->with(['member', 'recorder'])->dateRange($startDate, $endDate)
            ->orderBy('transaction_date')->orderBy('id');

        foreach ($query->lazy() as $saving) {
            yield [
                $saving->transaction_reference,
                $saving->transaction_date->format('Y-m-d'),
                $saving->member->member_number ?? '',
                $saving->member->full_name ?? '',
                $saving->type,
                (float) $saving->amount,
                $saving->payment_method,
                $saving->payment_reference ?? '',
                $saving->recorder->name ?? '',
            ];
        }
    }

    /**
     * Loan portfolio rows for export.
     *
     * @return iterable<int, list<string|float|int>>
     */
    public function loansExportRows(?string $status): iterable
    {
        $query = Loan::query()->with(['member', 'repayments'])->status($status)->orderBy('applied_at')->orderBy('id');

        foreach ($query->lazy() as $loan) {
            yield [
                $loan->loan_reference,
                $loan->member->member_number ?? '',
                $loan->member->full_name ?? '',
                $loan->status,
                (float) $loan->principal,
                (float) $loan->total_amount,
                $loan->total_repaid,
                $loan->remaining_balance,
                $loan->applied_at->format('Y-m-d'),
                $loan->due_date?->format('Y-m-d') ?? '',
                $loan->days_overdue,
                $loan->risk_score !== null ? (float) $loan->risk_score : '',
                $loan->decision ? LendingDecisionEngine::label($loan->decision) : '',
            ];
        }
    }
}
