<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Database\Factories\LendingPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class LendingPolicy extends Model
{
    /** @use HasFactory<LendingPolicyFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'approve_below_score',
        'reject_at_or_above_score',
        'max_loan_to_savings_ratio',
        'min_tenure_months',
        'reject_on_past_default',
    ];

    /**
     * Default values mirrored from the migration so unsaved instances behave identically.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'approve_below_score' => 50.00,
        'reject_at_or_above_score' => 75.00,
        'max_loan_to_savings_ratio' => 3.00,
        'min_tenure_months' => 3,
        'reject_on_past_default' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approve_below_score' => 'float',
            'reject_at_or_above_score' => 'float',
            'max_loan_to_savings_ratio' => 'float',
            'min_tenure_months' => 'integer',
            'reject_on_past_default' => 'boolean',
        ];
    }

    /**
     * Boot model: every threshold change is audited, because it changes future lending decisions.
     */
    protected static function booted(): void
    {
        static::updated(function (LendingPolicy $policy) {
            $changes = [];

            foreach (Arr::except($policy->getChanges(), ['updated_at']) as $field => $new) {
                $changes[$field] = [$policy->getOriginal($field), $new];
            }

            if ($changes !== []) {
                app(AuditTrail::class)->record('policy.updated', $policy, ['changes' => $changes]);
            }
        });
    }

    /**
     * Fetch a SACCO's lending policy, creating one with default thresholds on first use.
     */
    public static function resolveFor(int $saccoId): self
    {
        return static::withoutTenantScope()->firstOrCreate(['sacco_id' => $saccoId]);
    }
}
