<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One attempt to collect money from a member. Rows are never deleted. The amount, member and SACCO
 * are set by the server when the row is created and are never taken from the browser or a webhook.
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant;

    public const PURPOSE_DEPOSIT = 'deposit';

    public const PURPOSE_REPAYMENT = 'repayment';

    public const OPEN_STATUSES = ['initiated', 'pending'];

    /** @var list<string> */
    protected $fillable = [
        'sacco_id', 'member_id', 'loan_id', 'purpose', 'tx_ref', 'provider', 'provider_reference',
        'amount', 'currency', 'fee', 'payer_phone', 'network', 'status', 'failure_reason',
        'checkout_url', 'saving_id', 'repayment_id', 'provider_response', 'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'provider_response' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new RuntimeException('Payment records are never deleted.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'tx_ref';
    }

    public static function generateReference(int $saccoId): string
    {
        return sprintf('PAY-%d-%s', $saccoId, strtoupper(Str::random(16)));
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Method recorded on the savings or repayment ledger row.
     */
    public function ledgerPaymentMethod(): string
    {
        return match ($this->network) {
            'mtn' => 'mtn_momo',
            'airtel' => 'airtel_money',
            default => 'other',
        };
    }

    public function networkLabel(): string
    {
        return match ($this->network) {
            'mtn' => 'MTN Mobile Money',
            'airtel' => 'Airtel Money',
            default => 'Card',
        };
    }
}
