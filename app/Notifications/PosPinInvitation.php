<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks a staff member to set their POS PIN, with the link to the page where they do it. A PIN is never sent: staff set
 * their own under Account and confirm it with their password. It goes by mail only and skips the preference matrix.
 */
class PosPinInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $propertyName,
        public string $invitedBy,
        public string $pinUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Set your POS PIN'),
                'propertyName' => $this->propertyName,
            ])
            ->subject(__('Set your POS PIN at :property', ['property' => $this->propertyName]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':by asked you to set your POS PIN at :property. You key it at the register to sign in, and a manager keys theirs to approve voids and refunds.', ['by' => $this->invitedBy, 'property' => $this->propertyName]))
            ->action(__('Set my POS PIN'), $this->pinUrl)
            ->line(__('Sign in, pick 4 to 6 digits (6 if your role approves voids and refunds), and confirm with your password. Never share your PIN.'));
    }
}
