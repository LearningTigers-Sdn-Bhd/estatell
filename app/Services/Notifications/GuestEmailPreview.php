<?php

namespace App\Services\Notifications;

use App\Enums\GuestEmail;
use App\Models\Property;
use App\Services\EInvoice\EInvoiceGuestEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;

/**
 * A guest email as the guest gets it: the real mail template, rendered to HTML. Settings › Emails shows it with example
 * details while staff edit the wording, and the e-invoice Email to guest dialog shows it with the real details. Using
 * the template the email is sent with keeps the preview exact.
 */
class GuestEmailPreview
{
    public function __construct(private EInvoiceGuestEmail $eInvoiceGuestEmail) {}

    /**
     * The email with the given wording and example details.
     *
     * @return array{subject: string, reply_to: string|null, html: string}
     */
    public function example(Property $property, GuestEmail $email, string $subject, string $body): array
    {
        $filled = match ($email) {
            GuestEmail::EInvoice => $this->eInvoiceGuestEmail->example($property, $subject, $body),
        };
        $notification = match ($email) {
            GuestEmail::EInvoice => $this->eInvoiceGuestEmail->notification($filled),
        };

        return [
            'subject' => $filled['subject'],
            'reply_to' => $filled['reply_to'],
            'html' => $this->html($notification),
        ];
    }

    /**
     * The HTML of a mail notification, as the mail template renders it.
     */
    public function html(Notification $notification): string
    {
        /** @phpstan-ignore method.notFound (every guest email notification sends by mail) */
        return (string) $notification->toMail(new AnonymousNotifiable)->render();
    }
}
