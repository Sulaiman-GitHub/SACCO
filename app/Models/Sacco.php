<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sacco extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'license_number',
        'tier',
        'phone',
        'email',
        'address',
        'status',
    ];

    /**
     * The users belonging to this SACCO.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The registered members belonging to this SACCO.
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * The savings ledger transactions recorded for this SACCO.
     */
    public function savings(): HasMany
    {
        return $this->hasMany(Saving::class);
    }

    /**
     * Active scope.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
