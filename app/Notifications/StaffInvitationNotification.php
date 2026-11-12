<?php

namespace App\Notifications;

use App\Models\Sacco;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a new staff member their one-time link to set a password.
 * Carries no password, national ID, balance or member data.
 */
class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token, public Sacco $sacco, public int $validHours) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'name' => $notifiable->name,
            'saccoName' => $this->sacco->name,
            'appName' => 'SACCO Credit',
            'url' => route('staff.invitation.show', $this->token),
            'validHours' => $this->validHours,
        ];

        return (new MailMessage)
            ->subject("You have been invited to {$this->sacco->name} on SACCO Credit")
            ->view(['html' => 'emails.staff-invitation', 'text' => 'emails.staff-invitation-text'], $data);
    }
}
