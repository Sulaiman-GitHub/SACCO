<?php

namespace App\Services;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Notifications\Notification as NotificationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Issues, delivers and redeems staff invitation links.
 *
 * Tokens are random, shown only in the emailed link, and stored as a SHA-256 hash. These flows run for
 * signed-out visitors and for administrators of other SACCOs, so every lookup bypasses the tenant scope
 * and checks the owning user and SACCO explicitly instead.
 */
class StaffInvitations
{
    /**
     * Create a fresh invitation for a staff member, discarding any older unused ones so only the newest link works.
     * Returns the plain token, which exists only in memory and the email.
     */
    public function issue(User $user, ?User $createdBy = null): string
    {
        $token = Str::random(48);

        DB::transaction(function () use ($user, $createdBy, $token) {
            StaffInvitation::withoutTenantScope()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            StaffInvitation::create([
                'user_id' => $user->id,
                'sacco_id' => $user->sacco_id,
                'token_hash' => StaffInvitation::hashToken($token),
                'expires_at' => now()->addHours(StaffInvitation::VALID_HOURS),
                'created_by' => $createdBy?->id,
            ]);
        });

        return $token;
    }

    /**
     * Email the invitation. A mail failure is logged (never with the token) and reported as false,
     * so the account is never rolled back because of it.
     */
    public function deliver(User $user, string $token): bool
    {
        $user->loadMissing('sacco');

        return $this->send($user, new StaffInvitationNotification($token, $user->sacco, StaffInvitation::VALID_HOURS), 'staff invitation');
    }

    /**
     * Send a notification, queued only when MAIL_QUEUE is on; otherwise right away so a failure can be reported.
     */
    public function send(User $user, NotificationMessage $notification, string $what): bool
    {
        try {
            if (config('mail.queue_notifications')) {
                $user->notify($notification);
            } else {
                Notification::sendNow($user, $notification);
            }

            return true;
        } catch (Throwable $e) {
            Log::error("Could not send {$what} email.", ['user_id' => $user->id, 'error' => $e::class.': '.$e->getMessage()]);

            return false;
        }
    }

    /**
     * Find the invitation for a link, or null if it is unknown, used, expired, or its owner can no longer sign in.
     */
    public function findUsable(?string $token): ?StaffInvitation
    {
        if ($token === null || $token === '') {
            return null;
        }

        $hash = StaffInvitation::hashToken($token);
        $invitation = StaffInvitation::withoutTenantScope()->where('token_hash', $hash)->first();

        if ($invitation === null || ! hash_equals($invitation->token_hash, $hash) || ! $invitation->isUsable()) {
            return null;
        }

        $user = $invitation->user;

        if ($user === null || $user->sacco_id !== $invitation->sacco_id || ! $user->canUseStaffEmailFlows()) {
            return null;
        }

        return $invitation;
    }

    /**
     * Redeem an invitation: set the password, verify the address, and retire every pending link. Single use.
     * Returns the user, or null if the link was just used or expired.
     */
    public function accept(StaffInvitation $invitation, string $password): ?User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $claimed = StaffInvitation::withoutTenantScope()
                ->whereKey($invitation->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($claimed !== 1) {
                return null;
            }

            $user = User::withoutTenantScope()->findOrFail($invitation->user_id);
            $user->forceFill([
                'password' => $password,
                'email_verified_at' => now(),
            ])->save();

            $this->discardPending($user);

            return $user;
        });
    }

    /**
     * Remove a user's unused invitations, e.g. once they have a password of their own.
     */
    public function discardPending(User $user): void
    {
        StaffInvitation::withoutTenantScope()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->delete();
    }
}
