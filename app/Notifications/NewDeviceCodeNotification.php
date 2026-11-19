<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Account\NewDeviceCodes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 6-digit code for signing in on a new device. Sent at once, not queued: the user is waiting on the page. The
 * plain code lives only in this message; the database keeps its hash.
 */
class NewDeviceCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public ?string $device,
        public ?string $ipAddress,
        public ?string $place,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $details = array_filter([
            __('Device') => $this->device,
            __('IP address') => $this->ipAddress,
            __('Location') => $this->place,
        ]);

        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Your sign-in code'),
                'mailCode' => $this->code,
                'mailDetails' => $details === [] ? null : $details,
            ])
            ->subject(__(':code is your :app sign-in code', ['code' => $this->code, 'app' => config('app.name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Someone signed in to your account on a new device or browser. Enter this code to finish signing in.'))
            ->line(__('The code expires in :minutes minutes.', ['minutes' => NewDeviceCodes::VALID_MINUTES]))
            ->line(__('If this was not you, do not share the code and change your password.'));
    }
}
