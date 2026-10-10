<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Database\Factories\SavingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class Saving extends Model
{
    /** @use HasFactory<SavingFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Flag to permit deletion in maintenance or test tears-down scenarios.
     */
    public static bool $allowLedgerMutations = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'member_id',
        'transaction_reference',
        'type',
        'amount',
        'payment_method',
        'payment_reference',
        'transaction_date',
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
            'transaction_date' => 'date',
        ];
    }

    /**
     * Boot model and enforce append-only ledger immutability.
     */
    protected static function booted(): void
    {
        static::created(function (Saving $saving) {
            app(AuditTrail::class)->record('savings.recorded', $saving, [
                'reference' => $saving->transaction_reference,
                'member_id' => $saving->member_id,
                'type' => $saving->type,
                'amount' => (float) $saving->amount,
                'payment_method' => $saving->payment_method,
            ]);
        });

        static::updating(function () {
            if (! static::$allowLedgerMutations) {
                throw new RuntimeException('Financial ledger records are append-only and cannot be mutated in place.');
            }
        });

        static::deleting(function () {
            if (! static::$allowLedgerMutations) {
                throw new RuntimeException('Financial ledger records are append-only and cannot be deleted.');
            }
        });
    }

    /**
     * Get the member this transaction belongs to.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Get the staff user who recorded the transaction.
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Check if this transaction is a deposit.
     */
    public function isDeposit(): bool
    {
        return $this->type === 'deposit';
    }

    /**
     * Check if this transaction is a withdrawal.
     */
    public function isWithdrawal(): bool
    {
        return $this->type === 'withdrawal';
    }

    /**
     * Generate next transaction sequence reference for a given SACCO.
     */
    public static function generateReference(int $saccoId): string
    {
        $year = date('Y');
        $last = static::withoutTenantScope()
            ->where('sacco_id', $saccoId)
            ->latest('id')
            ->first();

        $nextSeq = $last ? ((int) $last->id + 1) : 1;

        return sprintf('SAV-%s-%05d', $year, $nextSeq);
    }

    /**
     * Scope query to deposit transactions.
     */
    public function scopeDeposits(Builder $query): Builder
    {
        return $query->where('type', 'deposit');
    }

    /**
     * Scope query to withdrawal transactions.
     */
    public function scopeWithdrawals(Builder $query): Builder
    {
        return $query->where('type', 'withdrawal');
    }

    /**
     * Scope query to a specific member.
     */
    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    /**
     * Scope search by transaction reference or member details.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $q) use ($term) {
            $q->where('transaction_reference', 'like', "%{$term}%")
                ->orWhere('payment_reference', 'like', "%{$term}%")
                ->orWhereHas('member', function (Builder $mq) use ($term) {
                    $mq->where('full_name', 'like', "%{$term}%")
                        ->orWhere('member_number', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%");
                });
        });
    }

    /**
     * Scope filter by payment method.
     */
    public function scopePaymentMethod(Builder $query, ?string $method): Builder
    {
        if (empty($method) || $method === 'all') {
            return $query;
        }

        return $query->where('payment_method', $method);
    }

    /**
     * Scope filter by transaction date range.
     */
    public function scopeDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if (! empty($startDate)) {
            $query->where('transaction_date', '>=', $startDate);
        }

        if (! empty($endDate)) {
            $query->where('transaction_date', '<=', $endDate);
        }

        return $query;
    }
}
