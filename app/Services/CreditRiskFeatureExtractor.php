<?php

namespace App\Services;

use App\Models\Member;
use Carbon\Carbon;

class CreditRiskFeatureExtractor
{
    /**
     * Contribution consistency used when a member has too few deposits to measure it. This is near the
     * volatile-saver average in the training data, so thin history is not mistaken for steadiness.
     */
    public const NO_HISTORY_CONSISTENCY = 1.0;

    /**
     * Range of contribution consistency seen in training; live values are kept inside it.
     */
    public const MIN_CONSISTENCY = 0.05;

    public const MAX_CONSISTENCY = 3.0;

    /**
     * Extract the complete 8-feature credit risk vector for a member and proposed loan amount.
     *
     * @return array{
     *     contribution_frequency: float,
     *     contribution_consistency: float,
     *     savings_balance: float,
     *     tenure_months: int,
     *     past_loans_count: int,
     *     past_default_count: int,
     *     on_time_repayment_rate: float,
     *     loan_to_savings_ratio: float
     * }
     */
    public function extractFeatures(Member $member, float $requestedPrincipal): array
    {
        $savingsBalance = (float) $member->savings_balance;
        $tenureMonths = (int) $member->tenure_months;

        return [
            'contribution_frequency' => $this->calculateContributionFrequency($member, $tenureMonths),
            'contribution_consistency' => $this->calculateContributionConsistency($member),
            'savings_balance' => max(0.0, round($savingsBalance, 2)),
            'tenure_months' => max(0, $tenureMonths),
            'past_loans_count' => (int) $member->loans()->where('status', 'closed')->count(),
            'past_default_count' => (int) $member->loans()->everDefaulted()->count(),
            'on_time_repayment_rate' => $this->calculateOnTimeRepaymentRate($member),
            'loan_to_savings_ratio' => $this->calculateLoanToSavingsRatio($requestedPrincipal, $savingsBalance),
        ];
    }

    /**
     * Calculate average deposit transactions per month.
     */
    public function calculateContributionFrequency(Member $member, int $tenureMonths): float
    {
        $depositCount = $member->savings()->where('type', 'deposit')->count();

        if ($depositCount === 0) {
            return 0.0;
        }

        $effectiveMonths = max(1, $tenureMonths);

        return round($depositCount / $effectiveMonths, 2);
    }

    /**
     * Calculate coefficient of variation (CV = std / mean) of deposit amounts.
     */
    public function calculateContributionConsistency(Member $member): float
    {
        $deposits = $member->savings()
            ->where('type', 'deposit')
            ->pluck('amount')
            ->map(fn (mixed $amount): float => (float) $amount)
            ->all();

        $count = count($deposits);

        // With fewer than two deposits there is no measurable pattern. Returning 0 would read as
        // "perfectly steady saver" and lower the risk, so thin history gets a volatile-end value.
        if ($count < 2) {
            return self::NO_HISTORY_CONSISTENCY;
        }

        $mean = array_sum($deposits) / $count;

        if ($mean <= 0.0) {
            return self::NO_HISTORY_CONSISTENCY;
        }

        $sumSquaredDiff = 0.0;
        foreach ($deposits as $deposit) {
            $sumSquaredDiff += pow($deposit - $mean, 2);
        }

        $variance = $sumSquaredDiff / ($count - 1);
        $stdDev = sqrt($variance);

        // Keep within the range the model was trained on
        return min(self::MAX_CONSISTENCY, max(self::MIN_CONSISTENCY, round($stdDev / $mean, 3)));
    }

    /**
     * Fraction of scheduled installments that were fully paid by their due date (0.0 to 1.0).
     *
     * Loans have no stored schedule, so one is derived: equal monthly installments of
     * total_amount / term_months, due 1, 2, ... term_months months after disbursement. An
     * installment is on time if cumulative repayments dated on or before its due date cover the
     * cumulative amount scheduled up to it. Only installments already due are counted, plus all of a
     * fully repaid loan's installments (paying early is on time). This matches the per-installment
     * measure the model was trained on.
     *
     * A loan that ever defaulted is always included, whatever its current status, and is treated as
     * fully repaid once its balance is cleared. Defaulting means the balance was still unpaid after
     * the final due date, so the last installment is always missed and a repaid-but-defaulted loan
     * can never read as a clean record.
     */
    public function calculateOnTimeRepaymentRate(Member $member): float
    {
        $loans = $member->loans()
            ->where(function ($query) {
                $query->whereIn('status', ['disbursed', 'overdue', 'defaulted', 'closed'])
                    ->orWhere('ever_defaulted', true);
            })
            ->whereNotNull('disbursed_at')
            ->with('repayments')
            ->get();

        $today = Carbon::now();
        $dueInstallments = 0;
        $paidOnTime = 0;

        foreach ($loans as $loan) {
            $term = (int) $loan->term_months;
            $total = (float) $loan->total_amount;

            if ($term < 1 || $total <= 0.0) {
                continue;
            }

            $installment = $total / $term;
            $fullyRepaid = $loan->status === 'closed' || $loan->remaining_balance <= 0.0;

            for ($k = 1; $k <= $term; $k++) {
                $dueDate = Carbon::parse($loan->disbursed_at)->addMonths($k)->endOfDay();

                if ($dueDate->gt($today) && ! $fullyRepaid) {
                    break;
                }

                $dueInstallments++;

                $paidByDueDate = (float) $loan->repayments
                    ->filter(fn ($repayment) => Carbon::parse($repayment->repayment_date)->lte($dueDate))
                    ->sum('amount');

                if ($paidByDueDate + 0.01 >= $installment * $k) {
                    $paidOnTime++;
                }
            }
        }

        // No installments have fallen due yet: clean-slate benchmark, same as the training data
        if ($dueInstallments === 0) {
            return 1.0;
        }

        return round($paidOnTime / $dueInstallments, 2);
    }

    /**
     * Calculate loan-to-savings leverage ratio.
     */
    public function calculateLoanToSavingsRatio(float $requestedPrincipal, float $savingsBalance): float
    {
        if ($savingsBalance <= 0.0) {
            return $requestedPrincipal > 0 ? 10.0 : 0.0;
        }

        $ratio = $requestedPrincipal / $savingsBalance;

        // Cap at 10.0 to avoid unbounded feature skew
        return min(10.0, max(0.1, round($ratio, 2)));
    }
}
