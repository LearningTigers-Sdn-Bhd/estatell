<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

abstract class PropertyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public NotificationType $type)
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
        if (! $notifiable instanceof User) {
            return [];
        }

        return $this->preferences()->channelsFor($notifiable, $this->type);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return in_array($channel, $this->via($notifiable), true);
    }

    private function preferences(): NotificationPreferences
    {
        return app(NotificationPreferences::class);
    }
}
