<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Database\Factories\SavingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public const MINIMUM_AMOUNT = 1000;

    /**
     * A partial withdrawal must be at least the minimum; withdrawing exactly
     * the full balance is always allowed, however small.
     */
    public static function meetsMinimum(float $amount, float $balance): bool
    {
        return $amount >= self::MINIMUM_AMOUNT || abs($amount - $balance) < 0.005;
    }

    /**
     * Record a deposit or withdrawal under a lock on the member row. The
     * balance checks run again inside the lock, so overlapping withdrawals
     * cannot overdraw the account.
     *
     * @param  array<string, mixed>  $data  Validated request data (member_id, type, amount, payment_method, transaction_date, ...)
     *
     * @throws ValidationException
     */
    public static function recordLocked(array $data, int $saccoId, ?int $recordedBy): self
    {
        return DB::transaction(function () use ($data, $saccoId, $recordedBy) {
            $member = Member::withoutTenantScope()
                ->where('sacco_id', $saccoId)
                ->lockForUpdate()
                ->findOrFail($data['member_id']);

            $amount = (float) $data['amount'];

            if (! $member->isActive()) {
                throw ValidationException::withMessages([
                    'member_id' => "Savings transactions can only be recorded for active members (current status: {$member->status}).",
                ]);
            }

            if ($data['type'] === 'withdrawal') {
                $balance = $member->savings_balance;
                if ($amount > $balance) {
                    throw ValidationException::withMessages([
                        'amount' => 'Insufficient savings balance. Member has UGX '.number_format($balance, 2).' available for withdrawal.',
                    ]);
                }
                if (! self::meetsMinimum($amount, $balance)) {
                    throw ValidationException::withMessages([
                        'amount' => 'The minimum withdrawal is UGX '.number_format(self::MINIMUM_AMOUNT).', unless you are withdrawing the member\'s full balance of UGX '.number_format($balance, 2).'.',
                    ]);
                }
            } elseif ($amount < self::MINIMUM_AMOUNT) {
                throw ValidationException::withMessages([
                    'amount' => 'The minimum deposit is UGX '.number_format(self::MINIMUM_AMOUNT).'.',
                ]);
            }

            return static::create([
                'sacco_id' => $saccoId,
                'member_id' => $member->id,
                'transaction_reference' => self::generateReference($saccoId),
                'type' => $data['type'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'recorded_by' => $recordedBy,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Reserve the next transaction reference for a SACCO. Call inside the saving transaction.
     */
    public static function generateReference(int $saccoId): string
    {
        return sprintf('SAV-%s-%05d', date('Y'), DocumentSequence::next($saccoId, 'saving'));
    }

    /**
     * The reference the next transaction will probably get (display only, not reserved).
     */
    public static function previewReference(int $saccoId): string
    {
        return sprintf('SAV-%s-%05d', date('Y'), DocumentSequence::peek($saccoId, 'saving'));
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
