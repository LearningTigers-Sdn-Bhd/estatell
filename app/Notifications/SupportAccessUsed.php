<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a property's owner that platform support viewed the property as its staff. Sent when the session ends, so
 * the duration is known. The data is copied in, because the session is gone by the time the message is built.
 */
class SupportAccessUsed extends PropertyNotification
{
    public function __construct(
        public string $supportUserName,
        public ?string $partnerName,
        public string $reason,
        public ?string $note,
        public CarbonImmutable $startedAt,
        public int $minutes,
        /** @var list<array{label: string, amount: ?string, reference: ?string}> */
        public array $moneyCorrections = [],
    ) {
        parent::__construct(NotificationType::SupportAccessUsed);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => __('Support session at :property', ['property' => $notifiable->property->name]),
                'propertyName' => $notifiable->property->name,
                'supportWho' => $this->who(),
                'mailDetails' => [__('Started') => $this->startedAt->toDayDateTimeString(), __('Duration') => __(':minutes min.', ['minutes' => $this->minutes]), __('Reason') => $this->reason],
                'supportNote' => $this->note,
                'supportCorrections' => array_map($this->moneyLine(...), $this->moneyCorrections),
            ])
            ->subject($this->title())
            ->line($this->who())
            ->line(__('Started :time.', ['time' => $this->startedAt->toDayDateTimeString()]))
            ->line(__('Reason: :reason.', ['reason' => $this->reason]))
            ->line(__('Duration: :minutes min.', ['minutes' => $this->minutes]));

        if ($this->note !== null) {
            $mail->line(__('Note: :note', ['note' => $this->note]));
        }

        if ($this->moneyCorrections !== []) {
            $mail->line(__('Money corrections:'));

            foreach ($this->moneyCorrections as $correction) {
                $mail->line('- '.$this->moneyLine($correction));
            }
        }

        return $mail->line(__('You can switch support access off in your property settings.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type->value,
            'title' => $this->title(),
            'body' => $this->who(),
            'support_user_name' => $this->supportUserName,
            'partner_name' => $this->partnerName,
            'reason' => $this->reason,
            'note' => $this->note,
            'started_at' => $this->startedAt->toIso8601String(),
            'minutes' => $this->minutes,
            ...($this->moneyCorrections === [] ? [] : ['money_corrections' => $this->moneyCorrections]),
        ];
    }

    /**
     * @param  array{label: string, amount: ?string, reference: ?string}  $correction
     */
    private function moneyLine(array $correction): string
    {
        $line = $correction['label'];

        if ($correction['amount'] !== null) {
            $line .= ' '.$correction['amount'];
        }

        if ($correction['reference'] !== null) {
            $line .= ' ('.__('reference :reference', ['reference' => $correction['reference']]).')';
        }

        return $line;
    }

    private function title(): string
    {
        return __('Support viewed your property');
    }

    private function who(): string
    {
        return $this->partnerName === null
            ? __(':name from estatell support viewed your property as a member of your staff.', ['name' => $this->supportUserName])
            : __(':name from :partner viewed your property as a member of your staff.', ['name' => $this->supportUserName, 'partner' => $this->partnerName]);
    }
}
