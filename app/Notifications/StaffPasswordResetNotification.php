<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a staff member a link to choose a new password. The link lives as long as the password broker's window.
 */
class StaffPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

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
            'appName' => 'SACCO Credit',
            'url' => route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]),
            'validMinutes' => (int) config('auth.passwords.users.expire'),
        ];

        return (new MailMessage)
            ->subject('Reset your SACCO Credit password')
            ->view(['html' => 'emails.staff-password-reset', 'text' => 'emails.staff-password-reset-text'], $data);
    }
}
