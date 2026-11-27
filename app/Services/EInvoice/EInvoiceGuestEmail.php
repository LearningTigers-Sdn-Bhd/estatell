<?php

namespace App\Services\EInvoice;

use App\Enums\EInvoiceEnvironment;
use App\Enums\GuestEmail;
use App\Models\EInvoiceSubmission;
use App\Models\Property;
use App\Notifications\EInvoiceForGuest;
use App\Services\Notifications\GuestEmailText;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The email a guest gets with their e-invoice, filled in from the property's text in Settings › Emails. The detail
 * page shows it as a preview and `EmailEInvoiceToGuest` sends it.
 */
class EInvoiceGuestEmail
{
    public function __construct(private GuestEmailText $text, private SubmissionRules $submissionRules) {}

    /**
     * @return array{to: string|null, subject: string, paragraphs: list<string>, details: array<string, string>, view_url: string|null, reply_to: string|null, property_name: string}
     */
    public function for(EInvoiceSubmission $submission): array
    {
        $property = $submission->property;
        $currency = $property->settings?->currency?->getCurrencyCode() ?? 'MYR';
        $total = data_get($submission->document_payload, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._');
        $amount = $total === null ? '' : $currency.' '.number_format((float) (string) BigDecimal::of((string) $total)->toScale(2), 2);
        $stayDates = $this->stayDates($submission);
        $values = [
            'guest_name' => (string) ($submission->buyer_snapshot['name'] ?? __('Guest')),
            'hotel_name' => $property->name,
            'invoice_number' => $submission->internal_id,
            'amount' => $amount,
            'stay_dates' => $stayDates ?? '',
        ];

        $details = [__('E-invoice') => $submission->internal_id];
        if ($amount !== '') {
            $details[__('Total with tax')] = $amount;
        }
        if ($stayDates !== null) {
            $details[__('Stay')] = $stayDates;
        }

        $email = trim((string) ($submission->buyer_snapshot['email'] ?? ''));

        return $this->compose(
            $property,
            $this->text->for($property, GuestEmail::EInvoice),
            $values,
            $details,
            $this->submissionRules->validationUrl($submission),
            filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
        );
    }

    /**
     * The email with the example details from `GuestEmail::placeholders()` and the given wording, for the preview in
     * Settings › Emails. The property name is the real one.
     *
     * @return array{to: string|null, subject: string, paragraphs: list<string>, details: array<string, string>, view_url: string|null, reply_to: string|null, property_name: string}
     */
    public function example(Property $property, string $subject, string $body): array
    {
        $values = array_column(GuestEmail::EInvoice->placeholders(), 'example', 'key');
        $values['hotel_name'] = $property->name;

        return $this->compose(
            $property,
            ['subject' => $subject, 'body' => $body],
            $values,
            [
                __('E-invoice') => $values['invoice_number'],
                __('Total with tax') => $values['amount'],
                __('Stay') => $values['stay_dates'],
            ],
            EInvoiceEnvironment::Production->portalUrl().'/EXAMPLE/share/EXAMPLE',
            null,
        );
    }

    /**
     * The notification that sends this email. A missing link (not yet known) becomes `#`, which only a preview uses.
     *
     * @param  array{subject: string, paragraphs: list<string>, details: array<string, string>, view_url: string|null, reply_to: string|null, property_name: string}  $email
     */
    public function notification(array $email): EInvoiceForGuest
    {
        return new EInvoiceForGuest(
            $email['subject'],
            $email['paragraphs'],
            $email['details'],
            $email['property_name'],
            $email['reply_to'],
            $email['view_url'] ?? '#',
        );
    }

    /**
     * @param  array{subject: string, body: string}  $text
     * @param  array<string, string>  $values
     * @param  array<string, string>  $details
     * @return array{to: string|null, subject: string, paragraphs: list<string>, details: array<string, string>, view_url: string|null, reply_to: string|null, property_name: string}
     */
    private function compose(Property $property, array $text, array $values, array $details, ?string $viewUrl, ?string $to): array
    {
        $body = $this->text->fill($text['body'], $values);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []), fn (string $paragraph): bool => $paragraph !== ''));

        return [
            'to' => $to,
            'subject' => $this->text->fill($text['subject'], $values),
            'paragraphs' => $paragraphs,
            'details' => $details,
            'view_url' => $viewUrl,
            'reply_to' => $property->contact_email,
            'property_name' => $property->name,
        ];
    }

    private function stayDates(EInvoiceSubmission $submission): ?string
    {
        $from = data_get($submission->document_payload, 'Invoice.0.InvoicePeriod.0.StartDate.0._');
        $to = data_get($submission->document_payload, 'Invoice.0.InvoicePeriod.0.EndDate.0._');
        if ($from === null || $to === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $from)->format('d M').' – '.CarbonImmutable::parse((string) $to)->format('d M Y');
    }
}
