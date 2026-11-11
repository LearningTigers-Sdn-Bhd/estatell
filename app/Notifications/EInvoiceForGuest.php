<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends a guest their e-invoice: the property's own subject and message (Settings › Emails), then the e-invoice details
 * and a View e-invoice button to MyInvois. A guest is not a user, so it goes by mail to the address staff entered and
 * skips the preference matrix. Replies go to the property. See docs/04-notifications/04-guest-emails.md.
 */
class EInvoiceForGuest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $paragraphs  The message, already filled in, one paragraph each.
     * @param  array<string, string>  $details  Label => value rows under the message.
     */
    public function __construct(
        public string $subject,
        public array $paragraphs,
        public array $details,
        public string $propertyName,
        public ?string $replyTo,
        public string $viewUrl,
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
        $message = (new MailMessage)
            ->markdown('notifications::email', [
                'mailTitle' => $this->subject,
                'propertyName' => $this->propertyName,
                'mailDetails' => $this->details,
            ])
            ->subject($this->subject);

        // Lines added after action() go below the button, so the message comes first.
        foreach ($this->paragraphs as $paragraph) {
            $message->line($paragraph);
        }

        $message->action(__('View e-invoice'), $this->viewUrl);

        if ($this->replyTo !== null && $this->replyTo !== '') {
            $message->replyTo($this->replyTo, $this->propertyName);
        }

        return $message;
    }
}
