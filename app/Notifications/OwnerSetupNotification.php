<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class OwnerSetupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $owner = $notifiable->loadMissing('property');

        return (new MailMessage)
            ->markdown('notifications::email', ['mailTitle' => __('Set up your owner account for :property', ['property' => $owner->property->name]), 'propertyName' => $owner->property->name, 'setupSteps' => [__('Set your password'), __('Verify your email address')]])
            ->subject(__('Set up your :app owner account', ['app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $owner->name]))
            ->line(__('An administrator created :property for you.', ['property' => $owner->property->name]))
            ->line(__('Set your password and verify your email address to access your owner account.'))
            ->action(__('Set up your account'), URL::temporarySignedRoute(
                'guest.owner-setup.show',
                now()->addDays(7),
                ['user' => $owner],
            ))
            ->line(__('Your account setup link expires in seven days.'));
    }
}
