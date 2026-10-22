<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Default overdue threshold: loans past due date up to 90 days.
     */
    public const GRACE_PERIOD_DAYS = 30;

    /**
     * Default policy threshold: loans past due date by more than 90 days are classified as defaulted.
     * Complies with Section 8 & Section 5.4 of SACCO System Architecture.
     */
    public const DEFAULT_THRESHOLD_DAYS = 90;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'member_id',
        'loan_reference',
        'principal',
        'interest_rate',
        'interest_calculation_type',
        'term_months',
        'total_interest',
        'total_amount',
        'purpose',
        'status',
        'ever_defaulted',
        'first_default_date',
        'applied_at',
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'disbursed_at',
        'disbursed_by',
        'disbursement_method',
        'disbursement_reference',
        'due_date',
        'guarantor_name',
        'guarantor_phone',
        'guarantor_relationship',
        'risk_score',
        'decision',
        'decision_reason',
        'created_by',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'total_interest' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'risk_score' => 'decimal:2',
            'term_months' => 'integer',
            'ever_defaulted' => 'boolean',
            'first_default_date' => 'date',
            'applied_at' => 'date',
            'due_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ];
    }

    /**
     * Boot model: keep default history permanent and record loan applications in the audit trail.
     */
    protected static function booted(): void
    {
        static::saving(function (Loan $loan) {
            // Default history is permanent: once set it can never be cleared or overwritten.
            if ($loan->exists && $loan->getOriginal('ever_defaulted')) {
                $loan->ever_defaulted = true;
                $loan->first_default_date = $loan->getRawOriginal('first_default_date');

                return;
            }

            // A loan can only be 'defaulted' if it defaulted, however the status was set.
            if ($loan->status === 'defaulted') {
                $loan->ever_defaulted = true;
                $loan->first_default_date ??= $loan->defaultDate() ?? Carbon::today();
            }
        });

        static::created(function (Loan $loan) {
            app(AuditTrail::class)->record('loan.applied', $loan, [
                'reference' => $loan->loan_reference,
                'member_id' => $loan->member_id,
                'principal' => (float) $loan->principal,
                'risk_score' => $loan->risk_score !== null ? (float) $loan->risk_score : null,
                'decision' => $loan->decision,
            ]);
        });
    }

    /**
     * Get the member who took this loan.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get all append-only repayment entries for this loan.
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class)->orderBy('repayment_date', 'asc')->orderBy('id', 'asc');
    }

    /**
     * Get the staff user who recorded the loan application.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the staff user who approved the loan.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the staff user who disbursed the loan funds.
     */
    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * Get the staff user who rejected the loan application.
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Total amount repaid to date from append-only repayment records.
     */
    public function getTotalRepaidAttribute(): float
    {
        return (float) $this->repayments()->sum('amount');
    }

    /**
     * Remaining unpaid loan balance: Total Payable - Total Repaid.
     */
    public function getRemainingBalanceAttribute(): float
    {
        $remaining = (float) $this->total_amount - $this->total_repaid;

        return max(0.0, round($remaining, 2));
    }

    /**
     * Repayment progress percentage (0 - 100%).
     */
    public function getRepaymentPercentageAttribute(): float
    {
        if ((float) $this->total_amount <= 0) {
            return 100.0;
        }

        $pct = ($this->total_repaid / (float) $this->total_amount) * 100;

        return min(100.0, max(0.0, round($pct, 1)));
    }

    /**
     * Check if the loan is currently overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        if (! in_array($this->status, ['disbursed', 'overdue', 'defaulted'])) {
            return false;
        }

        if ($this->remaining_balance <= 0) {
            return false;
        }

        if (! $this->due_date) {
            return false;
        }

        return Carbon::now()->startOfDay()->gt($this->due_date);
    }

    /**
     * Number of days overdue past the due date.
     */
    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue || ! $this->due_date) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(Carbon::now()->startOfDay(), false);
    }

    /**
     * Status check helper methods.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isDisbursed(): bool
    {
        return $this->status === 'disbursed';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    public function isDefaulted(): bool
    {
        return $this->status === 'defaulted';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Whether approving would go against the rule engine's recommendation.
     * True for a recommended rejection, or a required guarantor that has not been attached.
     */
    public function isApprovalOverride(): bool
    {
        return $this->decision === 'reject'
            || ($this->decision === 'require_guarantor' && empty($this->guarantor_name));
    }

    public function canBeRejected(): bool
    {
        return $this->status === 'pending';
    }

    public function canBeDisbursed(): bool
    {
        return $this->status === 'approved';
    }

    public function canAcceptRepayment(): bool
    {
        return in_array($this->status, ['disbursed', 'overdue', 'defaulted']) && $this->remaining_balance > 0;
    }

    /**
     * Transition loan state to Approved.
     */
    public function approve(User $approver, ?string $notes = null): bool
    {
        if (! $this->canBeApproved()) {
            throw new InvalidArgumentException("Loan cannot be approved from '{$this->status}' status.");
        }

        DB::transaction(function () use ($approver, $notes) {
            $override = $this->isApprovalOverride();

            $this->update([
                'status' => 'approved',
                'approved_at' => Carbon::now(),
                'approved_by' => $approver->id,
                'notes' => $notes ? trim(($this->notes ? $this->notes."\n" : '').'Approval Note: '.$notes) : $this->notes,
            ]);

            app(AuditTrail::class)->record('loan.approved', $this, [
                'reference' => $this->loan_reference,
                'decision' => $this->decision,
                'override' => $override,
                'notes' => $notes,
            ]);
        });

        return true;
    }

    /**
     * Transition loan state to Rejected.
     */
    public function reject(User $rejecter, string $reason): bool
    {
        if (! $this->canBeRejected()) {
            throw new InvalidArgumentException("Loan cannot be rejected from '{$this->status}' status.");
        }

        DB::transaction(function () use ($rejecter, $reason) {
            $this->update([
                'status' => 'rejected',
                'rejected_at' => Carbon::now(),
                'rejected_by' => $rejecter->id,
                'rejection_reason' => $reason,
            ]);

            app(AuditTrail::class)->record('loan.rejected', $this, [
                'reference' => $this->loan_reference,
                'decision' => $this->decision,
                'override' => $this->decision === 'approve',
                'reason' => $reason,
            ]);
        });

        return true;
    }

    /**
     * Transition loan state to Disbursed and establish maturity due date.
     */
    public function disburse(
        User $disburser,
        string $paymentMethod,
        ?string $paymentReference,
        Carbon|string $disbursementDate,
        ?string $notes = null
    ): bool {
        if (! $this->canBeDisbursed()) {
            throw new InvalidArgumentException("Loan cannot be disbursed from '{$this->status}' status.");
        }

        $disbursedCarbon = is_string($disbursementDate) ? Carbon::parse($disbursementDate) : $disbursementDate;
        $dueDate = (clone $disbursedCarbon)->addMonths((int) $this->term_months);

        DB::transaction(function () use ($disburser, $paymentMethod, $paymentReference, $disbursedCarbon, $dueDate, $notes) {
            $this->update([
                'status' => 'disbursed',
                'disbursed_at' => $disbursedCarbon,
                'disbursed_by' => $disburser->id,
                'disbursement_method' => $paymentMethod,
                'disbursement_reference' => $paymentReference,
                'due_date' => $dueDate,
                'notes' => $notes ? trim(($this->notes ? $this->notes."\n" : '').'Disbursement Note: '.$notes) : $this->notes,
            ]);

            app(AuditTrail::class)->record('loan.disbursed', $this, [
                'reference' => $this->loan_reference,
                'principal' => (float) $this->principal,
                'method' => $paymentMethod,
                'payment_reference' => $paymentReference,
                'disbursed_on' => $disbursedCarbon->format('Y-m-d'),
                'due_date' => $dueDate->format('Y-m-d'),
            ]);
        });

        return true;
    }

    /**
     * Recalculate and update the loan's operational status based on balance and due date.
     * Enforces default policy:
     * - Balance <= 0 -> 'closed'
     * - Days overdue > 90 -> 'defaulted'
     * - Days overdue > 0 -> 'overdue'
     * - Otherwise -> 'disbursed'
     */
    public function refreshStatus(): string
    {
        // Only disbursed, overdue, or defaulted loans have active repayment tracking
        if (! in_array($this->status, ['disbursed', 'overdue', 'defaulted'])) {
            return $this->status;
        }

        // Must run before the closed check: a loan repaid after the threshold passed still defaulted
        $this->recordDefaultIfDue();

        if ($this->remaining_balance <= 0.001) {
            $this->update(['status' => 'closed']);

            return 'closed';
        }

        if ($this->due_date && Carbon::now()->startOfDay()->gt($this->due_date)) {
            $daysPast = (int) $this->due_date->diffInDays(Carbon::now()->startOfDay(), false);

            if ($daysPast > static::DEFAULT_THRESHOLD_DAYS) {
                $newStatus = 'defaulted';
            } else {
                $newStatus = 'overdue';
            }

            if ($this->status !== $newStatus) {
                $this->update(['status' => $newStatus]);
            }

            return $newStatus;
        }

        if ($this->status !== 'disbursed') {
            $this->update(['status' => 'disbursed']);
        }

        return 'disbursed';
    }

    /**
     * First day on which the loan counts as defaulted: the day after the 90-day threshold past the due date.
     */
    public function defaultDate(): ?Carbon
    {
        return $this->due_date?->copy()->addDays(static::DEFAULT_THRESHOLD_DAYS + 1);
    }

    /**
     * Permanently record that the loan defaulted, if it has crossed the threshold with an unpaid balance.
     *
     * Checks the balance as it stood when the threshold was crossed, not today's, so a loan that is
     * only refreshed after being repaid late is still recorded. Never clears or overwrites an
     * existing record; the status field is left alone.
     */
    public function recordDefaultIfDue(): void
    {
        if ($this->ever_defaulted) {
            return;
        }

        $defaultDate = $this->defaultDate();

        if (! $defaultDate || $defaultDate->gt(Carbon::today())) {
            return;
        }

        $paidBeforeDefault = (float) $this->repayments()
            ->where('repayment_date', '<', $defaultDate->toDateString())
            ->sum('amount');

        if ($paidBeforeDefault + 0.001 < (float) $this->total_amount) {
            $this->update([
                'ever_defaulted' => true,
                'first_default_date' => $defaultDate,
            ]);
        }
    }

    /**
     * Calculate interest amount for given terms.
     */
    public static function calculateInterest(
        float $principal,
        float $interestRate,
        int $termMonths,
        string $calcType = 'flat'
    ): float {
        if ($principal <= 0 || $interestRate <= 0 || $termMonths <= 0) {
            return 0.0;
        }

        // Standard flat interest per annum: Principal * (Rate / 100) * (Term in Months / 12)
        $annualRate = $interestRate / 100.0;
        $termFraction = $termMonths / 12.0;

        return round($principal * $annualRate * $termFraction, 2);
    }

    /**
     * Reserve the next loan reference for a SACCO. Call inside the saving transaction.
     * Example: LN-2026-00001
     */
    public static function generateReference(int $saccoId): string
    {
        return sprintf('LN-%s-%05d', date('Y'), DocumentSequence::next($saccoId, 'loan'));
    }

    /**
     * The reference the next loan will probably get (display only, not reserved).
     */
    public static function previewReference(int $saccoId): string
    {
        return sprintf('LN-%s-%05d', date('Y'), DocumentSequence::peek($saccoId, 'loan'));
    }

    /**
     * Scopes.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('loan_reference', 'like', "%{$term}%")
                ->orWhere('purpose', 'like', "%{$term}%")
                ->orWhere('guarantor_name', 'like', "%{$term}%")
                ->orWhere('guarantor_phone', 'like', "%{$term}%")
                ->orWhereHas('member', function (Builder $mq) use ($term) {
                    $mq->where('full_name', 'like', "%{$term}%")
                        ->orWhere('member_number', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['disbursed', 'overdue']);
    }

    public function scopeDefaulted(Builder $query): Builder
    {
        return $query->where('status', 'defaulted');
    }

    /**
     * Loans that ever defaulted, including ones since repaid and closed.
     */
    public function scopeEverDefaulted(Builder $query): Builder
    {
        return $query->where('ever_defaulted', true);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'overdue');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }
}
