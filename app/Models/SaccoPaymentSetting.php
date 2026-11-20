<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SaccoPaymentSetting extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['sacco_id', 'provider', 'environment', 'credentials', 'webhook_secret', 'enabled'];

    /**
     * Never serialised, so credentials cannot leak through JSON, logs or views.
     *
     * @var list<string>
     */
    protected $hidden = ['credentials', 'webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /**
     * The usable settings for a SACCO, or null when payments are not set up or are switched off.
     * There is no signed-in staff user on webhooks or in the portal, so the SACCO is always explicit.
     */
    public static function enabledFor(int $saccoId): ?self
    {
        return static::withoutTenantScope()
            ->where('sacco_id', $saccoId)
            ->where('enabled', true)
            ->first();
    }
}
