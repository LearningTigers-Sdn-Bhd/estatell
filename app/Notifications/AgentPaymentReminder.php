<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to a travel agent about the payment schedule of one booking: `schedule` (sent once after booking, with
 * every stage), `reminder` (the stage is due within a day) or `overdue`. It goes to the company's contact email and
 * its active portal logins, by mail only. The data is copied in. See
 * docs/15-city-ledger/06-agent-payment-schedule.md#reminders.
 */
class AgentPaymentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{label: string, amount: string, due: string}>  $stages
     * @param  list<string>  $agentLines  What the agent pays, one text line each.
     * @param  list<string>  $guestLines  What the guest pays at the resort, one text line each.
     */
    public function __construct(
        public string $kind,
        public string $propertyName,
        public string $propertyCode,
        public int $reservationId,
        public string $reference,
        public string $arrival,
        public array $stages,
        public string $amountDue,
        public string $dueText,
        public array $agentLines = [],
        public array $guestLines = [],
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

    public function toMail(object $notifiable): MailMessage
    {
        $title = match ($this->kind) {
            'schedule' => __('Payment schedule for :reference', ['reference' => $this->reference]),
            'reminder' => __('Payment due soon for :reference', ['reference' => $this->reference]),
            default => __('Payment overdue for :reference', ['reference' => $this->reference]),
        };

        $mail = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => $title,
                'propertyName' => $this->propertyName,
                'mailDetails' => [__('Booking') => $this->reference, __('Arrival') => $this->arrival],
            ])
            ->subject($title.' · '.$this->propertyName)
            ->line(match ($this->kind) {
                'schedule' => __('Thank you for your booking. Please pay as below.'),
                'reminder' => __('Please pay :amount by :due.', ['amount' => $this->amountDue, 'due' => $this->dueText]),
                default => __(':amount was due by :due and is not paid yet. Please pay now, or send us your payment proof.', ['amount' => $this->amountDue, 'due' => $this->dueText]),
            });

        if ($this->agentLines !== []) {
            $mail->line(__('**You pay**'));

            foreach ($this->agentLines as $line) {
                $mail->line($line);
            }
        }

        if ($this->guestLines !== []) {
            $mail->line(__('**The guest pays at the resort**'));

            foreach ($this->guestLines as $line) {
                $mail->line($line);
            }
        }

        foreach ($this->stages as $stage) {
            $mail->line(__(':label: :amount by :due', $stage));
        }

        return $mail
            ->line(__('Upload your payment proof on the booking in the company portal.'))
            ->action(__('Open the booking'), route('portal.bookings.show', ['property' => $this->propertyCode, 'reservation' => $this->reservationId]));
    }
}
