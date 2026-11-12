<?php

namespace App\Services\EInvoice;

use App\Enums\GuestEmail;
use App\Models\EInvoiceSubmission;
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

        $text = $this->text->for($property, GuestEmail::EInvoice);
        $body = $this->text->fill($text['body'], $values);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $body) ?: []), fn (string $paragraph): bool => $paragraph !== ''));

        $details = [__('E-invoice') => $submission->internal_id];
        if ($amount !== '') {
            $details[__('Total with tax')] = $amount;
        }
        if ($stayDates !== null) {
            $details[__('Stay')] = $stayDates;
        }

        $email = trim((string) ($submission->buyer_snapshot['email'] ?? ''));

        return [
            'to' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'subject' => $this->text->fill($text['subject'], $values),
            'paragraphs' => $paragraphs,
            'details' => $details,
            'view_url' => $this->submissionRules->validationUrl($submission),
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
