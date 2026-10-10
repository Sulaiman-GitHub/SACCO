<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Database\Factories\RepaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class Repayment extends Model
{
    /** @use HasFactory<RepaymentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Flag to permit deletion in test teardown scenarios.
     */
    public static bool $allowLedgerMutations = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'loan_id',
        'member_id',
        'repayment_reference',
        'amount',
        'payment_method',
        'payment_reference',
        'repayment_date',
        'recorded_by',
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
            'amount' => 'decimal:2',
            'repayment_date' => 'date',
        ];
    }

    /**
     * Boot model and enforce append-only ledger immutability.
     */
    protected static function booted(): void
    {
        static::created(function (Repayment $repayment) {
            app(AuditTrail::class)->record('repayment.recorded', $repayment, [
                'reference' => $repayment->repayment_reference,
                'loan_id' => $repayment->loan_id,
                'member_id' => $repayment->member_id,
                'amount' => (float) $repayment->amount,
                'payment_method' => $repayment->payment_method,
            ]);
        });

        static::updating(function () {
            if (! static::$allowLedgerMutations) {
                throw new RuntimeException('Loan repayment ledger records are append-only and cannot be mutated in place.');
            }
        });

        static::deleting(function () {
            if (! static::$allowLedgerMutations) {
                throw new RuntimeException('Loan repayment ledger records are append-only and cannot be deleted.');
            }
        });
    }

    /**
     * Get the loan associated with this repayment.
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the member this repayment belongs to.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the staff user who recorded the repayment.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Generate unique sequential repayment reference per SACCO.
     * Example: REP-2026-00001
     */
    public static function generateReference(int $saccoId): string
    {
        $year = date('Y');
        $last = static::withoutTenantScope()
            ->where('sacco_id', $saccoId)
            ->latest('id')
            ->first();

        $nextSeq = $last ? ((int) $last->id + 1) : 1;

        return sprintf('REP-%s-%05d', $year, $nextSeq);
    }

    /**
     * Scopes.
     */
    public function scopeForLoan(Builder $query, int $loanId): Builder
    {
        return $query->where('loan_id', $loanId);
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    public function scopePaymentMethod(Builder $query, ?string $method): Builder
    {
        if (empty($method) || $method === 'all') {
            return $query;
        }

        return $query->where('payment_method', $method);
    }

    public function scopeDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if (! empty($startDate)) {
            $query->where('repayment_date', '>=', $startDate);
        }

        if (! empty($endDate)) {
            $query->where('repayment_date', '<=', $endDate);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('repayment_reference', 'like', "%{$term}%")
                ->orWhere('payment_reference', 'like', "%{$term}%")
                ->orWhereHas('loan', function (Builder $lq) use ($term) {
                    $lq->where('loan_reference', 'like', "%{$term}%");
                })
                ->orWhereHas('member', function (Builder $mq) use ($term) {
                    $mq->where('full_name', 'like', "%{$term}%")
                        ->orWhere('member_number', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }
}
