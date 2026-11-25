<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells staff who manage settings that the AI assistant has used 80% of its monthly cap this month. At the cap every
 * chat goes to staff, so they can raise the cap first. Sent once a month. It opens Settings › AI assistant › Usage.
 * See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class AiCapNearlyReached extends PropertyNotification
{
    public function __construct(
        public string $propertyCode,
        public string $propertyName,
        public string $spentUsd,
        public string $capUsd,
    ) {
        parent::__construct(NotificationType::AiCapNearlyReached);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => $this->title(),
                'propertyName' => $this->propertyName,
            ])
            ->subject($this->title())
            ->line($this->body())
            ->action(__('Open usage'), $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'property_code' => $this->propertyCode,
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return __('The AI assistant has used 80% of its monthly cap');
    }

    private function body(): string
    {
        return __('It has spent about US$:spent of your US$:cap cap this month. At the cap, every chat goes to your staff.', ['spent' => $this->spentUsd, 'cap' => $this->capUsd]);
    }

    private function url(): string
    {
        return route('settings.ai-assistant.usage', ['property' => $this->propertyCode]);
    }
}
