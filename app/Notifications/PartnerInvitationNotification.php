<?php

namespace App\Notifications;

use App\Models\PartnerInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartnerInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The plain token lives only in this message; the database keeps its hash.
     */
    public function __construct(public PartnerInvitation $invitation, public string $token)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing('partner', 'inviter');

        return (new MailMessage)
            ->markdown('notifications::email', ['mailTitle' => __('You are invited to join as a partner'), 'mailDetails' => [__('Partner') => $invitation->partner->name]])
            ->subject(__('Join :app as a partner', ['app' => config('app.name')]))
            ->greeting(__('Hello,'))
            ->line(__(':inviter invited you to join :app as a partner for :partner.', [
                'inviter' => $invitation->inviter->name ?? __('estatell support'),
                'app' => config('app.name'),
                'partner' => $invitation->partner->name,
            ]))
            ->action(__('Accept invitation'), route('partner-invitations.show', $this->token))
            ->line(__('Your invitation link expires on :date.', ['date' => $invitation->expires_at->toDayDateTimeString()]));
    }
}
