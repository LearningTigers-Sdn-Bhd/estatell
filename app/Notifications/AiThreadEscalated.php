<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells front desk staff that the AI assistant handed a chat over. It carries the place, the guest name when the thread
 * belongs to a stay, and the model's short summary, never the chat itself. The data is copied in, so the message does
 * not depend on the thread when it is built. It opens the chat in the AI chats inbox.
 * See docs/34-ai-assistant/03-places-and-routes.md#handover.
 */
class AiThreadEscalated extends PropertyNotification
{
    public function __construct(
        public string $threadId,
        public string $propertyCode,
        public string $propertyName,
        public string $placeLabel,
        public ?string $guestName,
        public ?int $reservationId,
        public string $summary,
    ) {
        parent::__construct(NotificationType::AiThreadEscalated);
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
            ->action(__('Open chat'), $this->url());
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
            'reservation_id' => $this->reservationId,
            'property_code' => $this->propertyCode,
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return $this->guestName === null
            ? __('A guest needs the front desk')
            : __(':guest needs the front desk', ['guest' => $this->guestName]);
    }

    private function body(): string
    {
        return __('The AI assistant handed over a chat (:place): :summary', ['place' => $this->placeLabel, 'summary' => $this->summary]);
    }

    private function url(): string
    {
        return route('ai-chats.index', ['property' => $this->propertyCode, 'thread' => $this->threadId]);
    }
}
