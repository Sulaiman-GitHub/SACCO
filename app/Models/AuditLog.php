<?php

namespace App\Models;

use App\Services\LendingDecisionEngine;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * Previous-hash value used for the first entry of each SACCO's chain.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'user_id',
        'user_name',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'prev_hash',
        'hash',
        'created_at',
    ];

    /**
     * Boot model and enforce an immutable audit trail. There is deliberately no bypass flag.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be modified.');
        });

        static::deleting(function () {
            throw new RuntimeException('Audit log entries are immutable and cannot be deleted.');
        });
    }

    /**
     * Decoded details payload.
     *
     * @return array<string, mixed>
     */
    public function detailsArray(): array
    {
        $decoded = json_decode((string) $this->getRawOriginal('details'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Plain-language names for the stored action codes.
     *
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'savings.recorded' => 'Savings transaction recorded',
        'repayment.recorded' => 'Repayment recorded',
        'loan.applied' => 'Loan application submitted',
        'loan.approved' => 'Loan approved',
        'loan.rejected' => 'Loan rejected',
        'loan.disbursed' => 'Loan disbursed',
        'policy.updated' => 'Lending policy updated',
        'member.consent_recorded' => 'Member consent recorded',
        'member.portal_credentials_issued' => 'Member portal login issued',
        'member.portal_disabled' => 'Member portal access switched off',
        'member.portal_password_changed' => 'Member changed portal password',
        'consent.change_requested' => 'Member asked to change consent',
        'consent.change_request_processed' => 'Consent request processed',
        'consent.change_request_dismissed' => 'Consent request dismissed',
    ];

    /**
     * Plain-language names for the kinds of record an entry can refer to.
     *
     * @var array<string, string>
     */
    private const ENTITY_LABELS = [
        'Saving' => 'Savings transaction',
        'Repayment' => 'Repayment',
        'Loan' => 'Loan',
        'Member' => 'Member',
        'LendingPolicy' => 'Lending policy',
        'ConsentChangeRequest' => 'Consent request',
    ];

    /**
     * Plain-language names for the details stored with an entry. Technical identifiers are left out.
     *
     * @var array<string, string>
     */
    private const DETAIL_LABELS = [
        'member_number' => 'Member number',
        'type' => 'Type',
        'amount' => 'Amount (UGX)',
        'principal' => 'Principal (UGX)',
        'payment_method' => 'Payment method',
        'method' => 'Method',
        'payment_reference' => 'Payment reference',
        'disbursed_on' => 'Disbursed on',
        'due_date' => 'Due date',
        'risk_score' => 'Credit risk score',
        'decision' => 'Policy recommendation',
        'override' => 'Went against the policy recommendation',
        'notes' => 'Remarks',
        'reason' => 'Reason',
        'notice_version' => 'Consent notice version',
        'data_processing' => 'Agreed to the SACCO using their information',
        'scoring_consent' => 'Agreed to credit scoring',
        'previous_scoring_consent' => 'Previous answer on credit scoring',
        'next_of_kin_informed' => 'Next of kin informed',
        'first_time' => 'First login issued',
        'changed_by' => 'Changed by',
        'requested_by' => 'Requested by',
        'request_type' => 'Request',
    ];

    /**
     * Plain-language names for the lending policy fields shown in "Lending policy updated" entries.
     *
     * @var array<string, string>
     */
    private const POLICY_FIELD_LABELS = [
        'approve_below_score' => 'Recommend approval below score',
        'reject_at_or_above_score' => 'Recommend rejection at or above score',
        'max_loan_to_savings_ratio' => 'Maximum loan-to-savings ratio',
        'min_tenure_months' => 'Minimum membership (months)',
        'reject_on_past_default' => 'Reject on a previous default',
    ];

    /**
     * Readable name for an action code, for lists and filters.
     */
    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    /**
     * Readable name for this entry's action.
     */
    public function actionName(): string
    {
        return self::actionLabel($this->action);
    }

    /**
     * Readable name for the kind of record this entry refers to.
     */
    public function entityName(): string
    {
        return self::ENTITY_LABELS[$this->entity_type] ?? $this->entity_type;
    }

    /**
     * The entry's details as label and value pairs a person can read.
     *
     * @return list<array{label: string, value: string}>
     */
    public function displayDetails(): array
    {
        $rows = [];

        foreach ($this->detailsArray() as $key => $value) {
            if (in_array($key, ['reference', 'member_id', 'loan_id'], true) || $value === null || $value === '') {
                continue;
            }

            if ($key === 'changes' && is_array($value)) {
                foreach ($value as $field => $pair) {
                    $label = self::POLICY_FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
                    $rows[] = ['label' => $label, 'value' => $this->plainValue($pair[0] ?? null).' to '.$this->plainValue($pair[1] ?? null)];
                }

                continue;
            }

            $rows[] = [
                'label' => self::DETAIL_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'value' => $this->plainValue($value, $key),
            ];
        }

        return $rows;
    }

    /**
     * Turn a stored value into text a person can read.
     */
    private function plainValue(mixed $value, ?string $key = null): string
    {
        if ($value === null) {
            return 'none';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->plainValue($item), $value));
        }

        if ($key === 'request_type') {
            return ConsentChangeRequest::TYPE_LABELS[$value] ?? str_replace('_', ' ', (string) $value);
        }

        if ($key === 'decision') {
            return LendingDecisionEngine::label((string) $value);
        }

        if (in_array($key, ['requested_by', 'changed_by'], true) && $value === 'member_portal') {
            return 'the member (portal)';
        }

        if (in_array($key, ['payment_method', 'method'], true)) {
            return ucwords(str_replace('_', ' ', (string) $value));
        }

        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
        }

        return (string) $value;
    }

    /**
     * Whether this entry records a decision against the lending policy's recommendation.
     */
    public function isOverride(): bool
    {
        return (bool) ($this->detailsArray()['override'] ?? false);
    }

    /**
     * Compute the chain hash from the raw stored values.
     *
     * @param  array{sacco_id: int|string, user_id: int|string|null, user_name: ?string, action: string, entity_type: string, entity_id: int|string, details: string, created_at: string}  $fields
     */
    public static function computeHash(array $fields, string $prevHash): string
    {
        return hash('sha256', implode('|', [
            $fields['sacco_id'],
            $fields['user_id'] ?? '',
            $fields['user_name'] ?? '',
            $fields['action'],
            $fields['entity_type'],
            $fields['entity_id'],
            $fields['details'],
            $fields['created_at'],
            $prevHash,
        ]));
    }

    /**
     * Scope by exact action name.
     */
    public function scopeAction(Builder $query, ?string $action): Builder
    {
        return empty($action) ? $query : $query->where('action', $action);
    }

    /**
     * Scope by entity type.
     */
    public function scopeEntityType(Builder $query, ?string $entityType): Builder
    {
        return empty($entityType) ? $query : $query->where('entity_type', $entityType);
    }

    /**
     * Scope by creation date range (inclusive).
     */
    public function scopeDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if (! empty($startDate)) {
            $query->where('created_at', '>=', $startDate.' 00:00:00');
        }

        if (! empty($endDate)) {
            $query->where('created_at', '<=', $endDate.' 23:59:59');
        }

        return $query;
    }
}
