<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A SACCO's savings withdrawal rules. A SACCO with no saved row gets the defaults below (they are
 * placeholders the SACCO admin is expected to review, not legal rules).
 */
class WithdrawalPolicy extends Model
{
    use BelongsToTenant;

    public const COVER_BLOCK = 'block_while_loan_active';

    public const COVER_KEEP_RATIO = 'keep_ratio_cover';

    /**
     * @var array<string, string>
     */
    public const COVER_MODE_LABELS = [
        self::COVER_BLOCK => 'No withdrawals while a loan is outstanding',
        self::COVER_KEEP_RATIO => 'Savings must keep covering the loan',
    ];

    public const PAYER_NEVER = 'never';

    public const PAYER_ABOVE_THRESHOLD = 'above_threshold';

    public const PAYER_ALWAYS = 'always';

    /**
     * @var array<string, string>
     */
    public const PAYER_RULE_LABELS = [
        self::PAYER_NEVER => 'Never: the same person may approve and pay',
        self::PAYER_ABOVE_THRESHOLD => 'For large requests: a different person must pay',
        self::PAYER_ALWAYS => 'Always: a different person must pay',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'notice_days',
        'loan_cover_mode',
        'early_withdrawal_fee_percent',
        'daily_limit_per_member',
        'admin_approval_threshold',
        'security_hold_days',
        'request_expiry_days',
        'payer_must_differ_from_approver',
    ];

    /**
     * Defaults mirrored from the migration so an unsaved instance behaves identically.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'notice_days' => 7,
        'loan_cover_mode' => self::COVER_BLOCK,
        'early_withdrawal_fee_percent' => 0.00,
        'daily_limit_per_member' => 1000000.00,
        'admin_approval_threshold' => 500000.00,
        'security_hold_days' => 3,
        'request_expiry_days' => 30,
        'payer_must_differ_from_approver' => self::PAYER_ABOVE_THRESHOLD,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notice_days' => 'integer',
            'early_withdrawal_fee_percent' => 'float',
            'daily_limit_per_member' => 'float',
            'admin_approval_threshold' => 'float',
            'security_hold_days' => 'integer',
            'request_expiry_days' => 'integer',
        ];
    }

    /**
     * The SACCO's rules, or an unsaved instance holding the defaults when none were saved yet.
     */
    public static function resolveFor(int $saccoId): self
    {
        return static::withoutTenantScope()->firstOrNew(['sacco_id' => $saccoId]);
    }

    public function offersEarlyWithdrawal(): bool
    {
        return $this->early_withdrawal_fee_percent > 0;
    }

    /**
     * The fee for withdrawing early, rounded to cents.
     */
    public function earlyFeeFor(float $amount): float
    {
        return round($amount * $this->early_withdrawal_fee_percent / 100, 2);
    }

    /**
     * Whether a request of this amount needs a SACCO admin to approve it.
     */
    public function needsAdminApproval(float $amount): bool
    {
        return $this->admin_approval_threshold === null || $amount > $this->admin_approval_threshold;
    }

    /**
     * Whether the person who approved a request of this size may also pay it out, under this SACCO's
     * separation-of-duties setting. Returns the reason it is blocked, or null.
     */
    public function payerRuleError(float $amount, ?int $approverId, int $payerId): ?string
    {
        if ($approverId === null || $approverId !== $payerId) {
            return null;
        }

        $mustDiffer = $this->payer_must_differ_from_approver === self::PAYER_ALWAYS
            || ($this->payer_must_differ_from_approver === self::PAYER_ABOVE_THRESHOLD && $this->needsAdminApproval($amount));

        return $mustDiffer
            ? 'You approved this request, and your SACCO requires someone else to record the payout. Ask another staff member to do it (a SACCO administrator can change this rule under Withdrawal Rules).'
            : null;
    }

    /**
     * Why the loan cover rule blocks taking $amount out of a balance, or null when it does not.
     * Used for member requests, for payouts, and for withdrawals recorded at the counter.
     */
    public function loanCoverError(Member $member, float $amount, float $balance): ?string
    {
        $outstanding = (float) Loan::withoutTenantScope()
            ->where('sacco_id', $member->sacco_id)
            ->where('member_id', $member->id)
            ->whereIn('status', Loan::STATUS_GROUPS['outstanding'])
            ->sum('principal');

        if ($outstanding <= 0) {
            return null;
        }

        $ratio = LendingPolicy::resolveFor((int) $member->sacco_id)->max_loan_to_savings_ratio;

        if ($this->loan_cover_mode === self::COVER_KEEP_RATIO && $ratio !== null && $ratio > 0) {
            $required = round($outstanding / $ratio, 2);
            $remaining = round($balance - $amount, 2);

            if ($remaining + 0.005 < $required) {
                $withdrawable = max(0, round($balance - $required, 2));

                return 'Savings must keep covering the outstanding loan. After this withdrawal at least UGX '.number_format($required, 2)
                    .' has to stay in savings; at most UGX '.number_format($withdrawable, 2).' can be withdrawn now.';
            }

            return null;
        }

        return 'Savings cannot be withdrawn while a loan is outstanding. They back the loan until it is fully repaid.';
    }

    /**
     * Why the daily limit blocks $amount, or null. $alreadyToday is what the member has already
     * withdrawn or asked to withdraw that day.
     */
    public function dailyLimitError(float $amount, float $alreadyToday): ?string
    {
        if ($this->daily_limit_per_member === null) {
            return null;
        }

        if ($alreadyToday + $amount > $this->daily_limit_per_member + 0.005) {
            $left = max(0, round($this->daily_limit_per_member - $alreadyToday, 2));

            return 'This is over the daily withdrawal limit of UGX '.number_format($this->daily_limit_per_member, 2)
                .' per member. UGX '.number_format($left, 2).' is still available today.';
        }

        return null;
    }
}
