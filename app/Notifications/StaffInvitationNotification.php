<?php

namespace App\Notifications;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The plain token lives only in this message; the database keeps its hash.
     */
    public function __construct(public StaffInvitation $invitation, public string $token)
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
        $invitation = $this->invitation->loadMissing('property', 'role', 'inviter');

        return (new MailMessage)
            ->subject(__('Join :property on :app', ['property' => $invitation->property->name, 'app' => config('app.name')]))
            ->line(__(':inviter invited you to join :property as :role.', [
                'inviter' => $invitation->inviter?->name ?? __('The property owner'),
                'property' => $invitation->property->name,
                'role' => $invitation->role->name,
            ]))
            ->action(__('Accept invitation'), route('invitations.show', $this->token))
            ->line(__('This link expires on :date.', ['date' => $invitation->expires_at->toDayDateTimeString()]));
    }
}
