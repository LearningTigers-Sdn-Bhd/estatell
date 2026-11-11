<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A company on credit terms sent a payment slip in the portal. Staff check it on the company's Payments tab.
 */
class PaymentSlipReceived extends PropertyNotification
{
    public function __construct(
        public int $companyId,
        public string $companyName,
        public string $propertyCode,
        public string $propertyName,
        public string $amount,
    ) {
        parent::__construct(NotificationType::PaymentSlipReceived);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->markdown('notifications::email', ['mailTitle' => $this->title(), 'propertyName' => $this->propertyName])
            ->subject($this->title())
            ->line($this->body())
            ->action(__('Check the slip'), $this->url());
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
        return __(':company sent a payment slip', ['company' => $this->companyName]);
    }

    private function body(): string
    {
        return __(':company sent a slip for :amount. It is not recorded until you accept it.', ['company' => $this->companyName, 'amount' => $this->amount]);
    }

    private function url(): string
    {
        return route('finance.city-ledger.companies.payments', ['property' => $this->propertyCode, 'company' => $this->companyId]);
    }
}
