<?php

namespace App\Models;

use App\Services\AuditTrail;
use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Member extends Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Version of the member-facing consent notice (DATA_PROTECTION_CONSENT.md section 3) that staff
     * read to members. Bump it whenever the wording changes, so each record shows what was agreed to.
     */
    public const CONSENT_NOTICE_VERSION = '1.0';

    /**
     * The attributes that are mass assignable. Consent fields are deliberately absent: they are only
     * written by recordConsent(), never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'member_number',
        'full_name',
        'national_id_hash',
        'national_id_masked',
        'phone',
        'email',
        'date_of_birth',
        'gender',
        'occupation',
        'address',
        'monthly_income',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'join_date',
        'status',
        'created_by',
        'notes',
    ];

    /**
     * The attributes hidden from serialization. Portal credential fields are never mass assignable
     * either: they are written only by the portal methods below.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'monthly_income' => 'decimal:2',
            'consented_at' => 'datetime',
            'scoring_consent' => 'boolean',
            'next_of_kin_informed' => 'boolean',
            'portal_enabled' => 'boolean',
            'portal_must_change_password' => 'boolean',
            'portal_last_login_at' => 'datetime',
        ];
    }

    /**
     * Whether this member may sign in to the portal right now: access switched on by staff, the
     * member active, and their SACCO active.
     */
    public function canUsePortal(): bool
    {
        return $this->portal_enabled && $this->isActive() && $this->sacco?->status === 'active';
    }

    /**
     * Switch portal access on (or reset it) and return a new temporary password, shown to staff once.
     *
     * The login name is generated on first use and kept afterwards. The member must choose their own
     * password at first sign-in. The audit entry never contains the password.
     */
    public function issuePortalCredentials(): string
    {
        return DB::transaction(function () {
            $firstTime = $this->portal_username === null;
            $temporaryPassword = Str::password(10, symbols: false);

            $this->forceFill([
                'portal_username' => $this->portal_username ?? self::generatePortalUsername(),
                'password' => Hash::make($temporaryPassword),
                'portal_enabled' => true,
                'portal_must_change_password' => true,
            ])->save();

            app(AuditTrail::class)->record('member.portal_credentials_issued', $this, [
                'member_number' => $this->member_number,
                'first_time' => $firstTime,
            ]);

            return $temporaryPassword;
        });
    }

    /**
     * Switch portal access off. The username and password are kept, so re-enabling needs a reset.
     */
    public function disablePortal(): void
    {
        DB::transaction(function () {
            $this->forceFill(['portal_enabled' => false])->save();

            app(AuditTrail::class)->record('member.portal_disabled', $this, [
                'member_number' => $this->member_number,
            ]);
        });
    }

    /**
     * Set the member's own chosen portal password and clear the first-login requirement.
     */
    public function changePortalPassword(string $newPassword): void
    {
        DB::transaction(function () use ($newPassword) {
            $this->forceFill([
                'password' => Hash::make($newPassword),
                'portal_must_change_password' => false,
            ])->save();

            app(AuditTrail::class)->record('member.portal_password_changed', $this, [
                'member_number' => $this->member_number,
                'changed_by' => 'member_portal',
            ]);
        });
    }

    /**
     * Generate a login name that is unique across every SACCO, because member numbers are not.
     */
    private static function generatePortalUsername(): string
    {
        do {
            $username = 'm'.random_int(1000000, 9999999);
        } while (static::withoutTenantScope()->where('portal_username', $username)->exists());

        return $username;
    }

    /**
     * Consent changes a member may ask for, given their current record.
     *
     * @return list<string>
     */
    public function availableConsentRequestTypes(): array
    {
        $types = [];

        if ($this->scoring_consent === true) {
            $types[] = ConsentChangeRequest::TYPE_WITHDRAW_SCORING;
        }

        if (! $this->canBeScored()) {
            $types[] = ConsentChangeRequest::TYPE_GRANT_SCORING;
        }

        $types[] = ConsentChangeRequest::TYPE_WITHDRAW_DATA_PROCESSING;

        return $types;
    }

    /**
     * Get the consent change requests the member has made from the portal.
     */
    public function consentChangeRequests(): HasMany
    {
        return $this->hasMany(ConsentChangeRequest::class)->latest('id');
    }

    /**
     * Get the staff user who captured the member's consent.
     */
    public function consentCapturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_captured_by');
    }

    /**
     * Whether any consent record exists for this member.
     */
    public function hasRecordedConsent(): bool
    {
        return $this->consent_version !== null;
    }

    /**
     * Whether the member has explicitly refused credit scoring.
     */
    public function hasDeclinedScoring(): bool
    {
        return $this->scoring_consent === false;
    }

    /**
     * Whether the member may be credit scored. This is the only scoring rule: a recorded consent
     * with scoring agreed. A member with no consent record is never scored.
     */
    public function canBeScored(): bool
    {
        return $this->hasRecordedConsent() && $this->scoring_consent === true;
    }

    /**
     * Record (or update) the member's consent and write it to the audit trail.
     *
     * Each call replaces the current record and appends an audit entry holding the previous scoring
     * choice, so the history of agreements and withdrawals is kept in the tamper-evident log.
     */
    public function recordConsent(User $capturedBy, bool $scoringConsent, bool $nextOfKinInformed): void
    {
        DB::transaction(function () use ($capturedBy, $scoringConsent, $nextOfKinInformed) {
            $previousScoringConsent = $this->scoring_consent;

            $this->forceFill([
                'consent_version' => self::CONSENT_NOTICE_VERSION,
                'consented_at' => Carbon::now(),
                'consent_captured_by' => $capturedBy->id,
                'scoring_consent' => $scoringConsent,
                'next_of_kin_informed' => $nextOfKinInformed,
            ])->save();

            app(AuditTrail::class)->record('member.consent_recorded', $this, [
                'member_number' => $this->member_number,
                'notice_version' => self::CONSENT_NOTICE_VERSION,
                'data_processing' => true,
                'scoring_consent' => $scoringConsent,
                'previous_scoring_consent' => $previousScoringConsent,
                'next_of_kin_informed' => $nextOfKinInformed,
            ]);
        });
    }

    /**
     * Get the staff user who registered this member.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the append-only savings ledger transactions for this member.
     */
    public function savings(): HasMany
    {
        return $this->hasMany(Saving::class);
    }

    /**
     * Get all loans taken by this member.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class)->latest('applied_at')->latest('id');
    }

    /**
     * Get all loan repayments made by this member.
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    /**
     * Get currently active loans for this member.
     */
    public function activeLoans(): HasMany
    {
        return $this->loans()->whereIn('status', ['disbursed', 'overdue']);
    }

    /**
     * Count of past completed loans.
     * Core ML feature for credit scoring (Section 7).
     */
    public function getPastLoansCountAttribute(): int
    {
        return $this->loans()->where('status', 'closed')->count();
    }

    /**
     * Count of loans that ever defaulted, including ones since repaid and closed.
     * Core ML feature for credit scoring (Section 7).
     */
    public function getPastDefaultCountAttribute(): int
    {
        return $this->loans()->everDefaulted()->count();
    }

    /**
     * Current total outstanding loan balance across all active loans.
     */
    public function getTotalLoanBalanceAttribute(): float
    {
        $activeLoans = $this->loans()->whereIn('status', ['disbursed', 'overdue', 'defaulted'])->get();

        return round($activeLoans->sum(fn ($loan) => $loan->remaining_balance), 2);
    }

    /**
     * Calculate loan-to-savings ratio for credit evaluation.
     * Core ML feature for credit scoring (Section 7).
     */
    public function loanToSavingsRatio(float $requestedPrincipal): float
    {
        $savings = $this->savings_balance;

        if ($savings <= 0) {
            return 999.0;
        }

        return round($requestedPrincipal / $savings, 2);
    }

    /**
     * Compute current running savings balance from append-only ledger entries.
     * Core Principle: running balance = SUM(deposits) - SUM(withdrawals).
     */
    public function getSavingsBalanceAttribute(): float
    {
        $deposits = (float) $this->savings()->where('type', 'deposit')->sum('amount');
        $withdrawals = (float) $this->savings()->where('type', 'withdrawal')->sum('amount');

        return round($deposits - $withdrawals, 2);
    }

    /**
     * Total lifetime deposits recorded.
     */
    public function getTotalDepositsAttribute(): float
    {
        return (float) $this->savings()->where('type', 'deposit')->sum('amount');
    }

    /**
     * Total lifetime withdrawals recorded.
     */
    public function getTotalWithdrawalsAttribute(): float
    {
        return (float) $this->savings()->where('type', 'withdrawal')->sum('amount');
    }

    /**
     * Check if member account is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if member account is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Calculate member's tenure in the SACCO in complete months.
     * Essential ML feature for credit risk scoring.
     */
    public function getTenureMonthsAttribute(): int
    {
        if (! $this->join_date) {
            return 0;
        }

        return (int) $this->join_date->diffInMonths(Carbon::now());
    }

    /**
     * Format and compute a SHA-256 hash for sensitive National ID (NIN).
     * Complies with Uganda Data Protection and Privacy Act (2019).
     */
    public static function hashNationalId(string $nationalId): string
    {
        $sanitized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nationalId));

        return hash('sha256', $sanitized);
    }

    /**
     * Produce a masked representation of the National ID for visual inspection.
     * Example: "CM82012345678K" -> "CM82******78K"
     */
    public static function maskNationalId(string $nationalId): string
    {
        $sanitized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nationalId));
        $len = strlen($sanitized);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        if ($len <= 7) {
            return substr($sanitized, 0, 2).str_repeat('*', $len - 3).substr($sanitized, -1);
        }

        return substr($sanitized, 0, 4).str_repeat('*', $len - 7).substr($sanitized, -3);
    }

    /**
     * Generate next member sequence number for a given SACCO.
     */
    public static function generateNextMemberNumber(int $saccoId): string
    {
        $lastMember = static::withoutTenantScope()
            ->where('sacco_id', $saccoId)
            ->latest('id')
            ->first();

        $nextSeq = $lastMember ? ((int) $lastMember->id + 1) : 1;

        return sprintf('MBR-%04d', $nextSeq);
    }

    /**
     * Search scope across full name, phone number, member number, and National ID hash/mask.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        $term = trim($term);
        $hashedTerm = static::hashNationalId($term);

        return $query->where(function (Builder $q) use ($term, $hashedTerm) {
            $q->where('full_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('member_number', 'like', "%{$term}%")
                ->orWhere('national_id_masked', 'like', "%{$term}%")
                ->orWhere('national_id_hash', $hashedTerm);
        });
    }

    /**
     * Filter by member membership status.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }
}
