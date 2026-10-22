<?php

namespace App\Services;

use App\Models\LendingPolicy;

class LendingDecisionEngine
{
    public const APPROVE = 'approve';

    public const REQUIRE_GUARANTOR = 'require_guarantor';

    public const REJECT = 'reject';

    /**
     * Severity ranking used to keep the strictest outcome across all triggered rules.
     *
     * @var array<string, int>
     */
    private const SEVERITY = [
        self::APPROVE => 0,
        self::REQUIRE_GUARANTOR => 1,
        self::REJECT => 2,
    ];

    /**
     * Apply a SACCO's lending policy to an ML risk assessment and produce an explainable recommendation.
     *
     * @param  array{
     *     success: bool,
     *     risk_score: float,
     *     risk_band: string,
     *     features: array{
     *         savings_balance: float,
     *         tenure_months: int,
     *         past_default_count: int,
     *         loan_to_savings_ratio: float
     *     }
     * }  $assessment  Output of CreditRiskScoringService::scoreFeatures()
     * @return array{decision: string, reason: string, rules: list<string>}
     */
    public function decide(array $assessment, LendingPolicy $policy, bool $hasGuarantor = false): array
    {
        $score = (float) $assessment['risk_score'];
        $features = $assessment['features'];

        $decision = self::APPROVE;
        $rules = [];

        if ($score >= $policy->reject_at_or_above_score) {
            $decision = $this->stricter($decision, self::REJECT);
            $rules[] = sprintf('Risk score %.1f is at or above the rejection threshold of %.1f.', $score, $policy->reject_at_or_above_score);
        } elseif ($score >= $policy->approve_below_score) {
            $decision = $this->stricter($decision, self::REQUIRE_GUARANTOR);
            $rules[] = sprintf('Risk score %.1f is between the approval limit (%.1f) and the rejection threshold (%.1f).', $score, $policy->approve_below_score, $policy->reject_at_or_above_score);
        } else {
            $rules[] = sprintf('Risk score %.1f is below the approval limit of %.1f.', $score, $policy->approve_below_score);
        }

        if ((int) $features['past_default_count'] > 0) {
            $decision = $this->stricter($decision, $policy->reject_on_past_default ? self::REJECT : self::REQUIRE_GUARANTOR);
            $rules[] = sprintf('Member has %d previous default(s) in this SACCO.', $features['past_default_count']);
        }

        $maxRatio = $policy->max_loan_to_savings_ratio;
        if ($maxRatio !== null && (float) $features['loan_to_savings_ratio'] > $maxRatio) {
            $decision = $this->stricter($decision, self::REQUIRE_GUARANTOR);
            $rules[] = sprintf('Loan is %.2fx the member\'s savings, above the policy cap of %.2fx.', $features['loan_to_savings_ratio'], $maxRatio);
        }

        if ((int) $features['tenure_months'] < $policy->min_tenure_months) {
            $decision = $this->stricter($decision, self::REQUIRE_GUARANTOR);
            $rules[] = sprintf('Member tenure of %d month(s) is below the policy minimum of %d.', $features['tenure_months'], $policy->min_tenure_months);
        }

        return [
            'decision' => $decision,
            'reason' => $this->explain($decision, $rules, $hasGuarantor, (bool) ($assessment['success'] ?? true)),
            'rules' => $rules,
        ];
    }

    /**
     * Human-readable label for a decision code.
     */
    public static function label(?string $decision): string
    {
        return match ($decision) {
            self::APPROVE => 'Recommend Approval',
            self::REQUIRE_GUARANTOR => 'Guarantor Required',
            self::REJECT => 'Recommend Rejection',
            default => 'Not Assessed',
        };
    }

    /**
     * Keep whichever of the two decisions is stricter.
     */
    private function stricter(string $current, string $candidate): string
    {
        return self::SEVERITY[$candidate] > self::SEVERITY[$current] ? $candidate : $current;
    }

    /**
     * Compose the plain-English explanation stored with the loan.
     *
     * @param  list<string>  $rules
     */
    private function explain(string $decision, array $rules, bool $hasGuarantor, bool $modelAvailable): string
    {
        $headline = match ($decision) {
            self::APPROVE => 'Recommended for approval.',
            self::REJECT => 'Recommended for rejection.',
            default => $hasGuarantor
                ? 'Guarantor required by policy; a guarantor has been attached.'
                : 'Guarantor required by policy; none has been attached yet.',
        };

        $text = $headline.' '.implode(' ', $rules);

        if (! $modelAvailable) {
            $text .= ' Note: the credit scoring service was unavailable, so a simplified estimate was used for the score.';
        }

        return $text;
    }
}
