<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A member's request to withdraw savings. It never touches the savings ledger by itself: a ledger
 * row only appears when staff record the payout (see WithdrawalRequestService::recordPayout()).
 * Rows are kept for good, even when cancelled, rejected or expired.
 */
class WithdrawalRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Waiting for review',
        self::STATUS_APPROVED => 'Approved, waiting for payout',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_PAID => 'Paid',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_EXPIRED => 'Expired',
    ];

    /**
     * @var array<string, string>
     */
    public const PAYOUT_METHODS = [
        'mtn_momo' => 'MTN Mobile Money',
        'airtel_money' => 'Airtel Money',
        'bank_transfer' => 'Bank transfer',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    /**
     * Nothing is mass assignable except what is set when the request is created; every later change
     * goes through WithdrawalRequestService, which sets the fields explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'member_id',
        'reference',
        'amount',
        'early',
        'fee_amount',
        'net_amount',
        'destination_phone',
        'status',
        'available_from',
        'member_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'early' => 'boolean',
            'available_from' => 'date',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new RuntimeException('Withdrawal requests are kept for good and cannot be deleted.');
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(Saving::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst($this->status);
    }

    public function payoutMethodLabel(): ?string
    {
        return $this->payout_method === null ? null : (self::PAYOUT_METHODS[$this->payout_method] ?? ucwords(str_replace('_', ' ', $this->payout_method)));
    }

    /**
     * Reserve the next reference for a SACCO, such as WDR-2026-00001. Call inside the creating transaction.
     */
    public static function generateReference(int $saccoId): string
    {
        return sprintf('WDR-%s-%05d', date('Y'), DocumentSequence::next($saccoId, 'withdrawal_request'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return empty($status) || $status === 'all' ? $query : $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('reference', 'like', "%{$term}%")
                ->orWhereHas('member', function (Builder $mq) use ($term) {
                    $mq->where('full_name', 'like', "%{$term}%")
                        ->orWhere('member_number', 'like', "%{$term}%");
                });
        });
    }
}
