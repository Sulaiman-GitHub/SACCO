<?php

namespace App\Models;

use App\Notifications\StaffPasswordResetNotification;
use App\Services\StaffInvitations;
use App\Traits\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sacco_id',
        'name',
        'email',
        'password',
        'role',
        'phone',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user is platform Super Administrator.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if user is SACCO Administrator.
     */
    public function isSaccoAdmin(): bool
    {
        return $this->role === 'sacco_admin';
    }

    /**
     * Check if user is Loan Officer.
     */
    public function isLoanOfficer(): bool
    {
        return $this->role === 'loan_officer';
    }

    /**
     * Check if user account is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The newest invitation that has not been used yet (it may have expired).
     */
    public function pendingInvitation(): HasOne
    {
        return $this->hasOne(StaffInvitation::class)->whereNull('used_at')->latestOfMany();
    }

    /**
     * Where the person stands with their invitation: 'active', 'invited' or 'invitation_expired'.
     * Anyone who has not confirmed their address and still has an unused invitation is waiting to accept it.
     */
    public function invitationState(): string
    {
        $invitation = $this->pendingInvitation;

        if ($this->email_verified_at !== null || $invitation === null) {
            return 'active';
        }

        return $invitation->isUsable() ? 'invited' : 'invitation_expired';
    }

    /**
     * Whether this person may receive invitation and password-reset emails and use their links:
     * an active SACCO administrator or loan officer of an active SACCO.
     */
    public function canUseStaffEmailFlows(): bool
    {
        if (! in_array($this->role, ['sacco_admin', 'loan_officer'], true) || ! $this->isActive()) {
            return false;
        }

        $sacco = $this->sacco;

        return $sacco !== null && $sacco->status === 'active';
    }

    /**
     * Send the staff password-reset email (called by the password broker).
     */
    public function sendPasswordResetNotification($token): void
    {
        app(StaffInvitations::class)->send($this, new StaffPasswordResetNotification($token), 'password reset');
    }
}
