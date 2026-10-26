<?php

namespace App\Notifications;

use App\Enums\NotificationType;

/**
 * Tells a property's owner, in the app only, that platform support made a money correction (a refund, void, credit
 * note or allocation reversal) while viewing the property as its staff. Sent once for each correction, right after it
 * is logged.
 */
class SupportAccessUsed extends PropertyNotification
{
    public function __construct(
        public string $supportUserName,
        public ?string $partnerName,
        public string $label,
        public ?string $amount,
        public ?string $reference,
    ) {
        parent::__construct(NotificationType::SupportAccessUsed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => __('Support made a money correction'),
            'body' => $this->body(),
            'support_user_name' => $this->supportUserName,
            'partner_name' => $this->partnerName,
            'label' => $this->label,
            'amount' => $this->amount,
            'reference' => $this->reference,
        ];
    }

    private function body(): string
    {
        $correction = $this->label;

        if ($this->amount !== null) {
            $correction .= ' '.$this->amount;
        }

        if ($this->reference !== null) {
            $correction .= ' ('.__('reference :reference', ['reference' => $this->reference]).')';
        }

        return $this->partnerName === null
            ? __(':name from estatell support made a money correction: :correction.', ['name' => $this->supportUserName, 'correction' => $correction])
            : __(':name from :partner made a money correction: :correction.', ['name' => $this->supportUserName, 'partner' => $this->partnerName, 'correction' => $correction]);
    }
}
