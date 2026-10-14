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
            ->subject(__('Join :app as a partner', ['app' => config('app.name')]))
            ->line(__(':inviter invited you to bring properties to :app as :partner.', [
                'inviter' => $invitation->inviter?->name ?? __('estatell support'),
                'app' => config('app.name'),
                'partner' => $invitation->partner->name,
            ]))
            ->action(__('Accept invitation'), route('partner-invitations.show', $this->token))
            ->line(__('This link expires on :date.', ['date' => $invitation->expires_at->toDayDateTimeString()]));
    }
}
