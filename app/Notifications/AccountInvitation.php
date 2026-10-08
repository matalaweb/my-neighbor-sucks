<?php

namespace App\Notifications;

use App\Models\Account;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation email. Contains an expiring link only — never a password.
 */
class AccountInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public Account $account,
        public Invitation $invitation,
        private string $token,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to '.$this->account->name.' on Noise Monitor')
            ->line('You were invited as a '.$this->invitation->role->getLabel().' of '.$this->account->name.'.')
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This link expires '.$this->invitation->expires_at->toDayDateTimeString().' UTC. You will choose your own password.');
    }
}
