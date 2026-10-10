<?php

namespace App\Traits;

use App\Models\Sacco;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    /**
     * Boot the BelongsToTenant trait for the model.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->sacco_id) && Auth::check() && ! empty(Auth::user()->sacco_id)) {
                $model->sacco_id = Auth::user()->sacco_id;
            }
        });
    }

    /**
     * Get the SACCO that owns the model.
     */
    public function sacco(): BelongsTo
    {
        return $this->belongsTo(Sacco::class);
    }

    /**
     * Scope query to bypass tenant scoping.
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Scope query to a specific SACCO.
     */
    public function scopeForSacco(Builder $query, int $saccoId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)->where($this->qualifyColumn('sacco_id'), $saccoId);
    }
}
