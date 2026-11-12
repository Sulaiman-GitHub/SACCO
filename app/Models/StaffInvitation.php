<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time, expiring invitation for a staff member to set their own password.
 * Only the SHA-256 hash of the emailed token is stored.
 */
class StaffInvitation extends Model
{
    use BelongsToTenant;

    /** Hours an invitation link stays valid. */
    public const VALID_HOURS = 48;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'sacco_id',
        'token_hash',
        'expires_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * The invited staff member.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /**
     * Whether the link can still be used.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * The one-way hash under which a token is stored and looked up.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
