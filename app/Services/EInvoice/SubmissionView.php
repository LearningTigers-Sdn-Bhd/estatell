<?php

namespace App\Services\EInvoice;

use App\Data\Shared\BadgeData;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceStatus;
use App\Models\EInvoiceSubmission;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The e-invoice detail page as a hotel manager reads it: what was billed, to whom, whether LHDN accepted it, and what
 * to do next. LHDN identifiers stay out of it, apart from the one reference LHDN support asks for.
 */
class SubmissionView
{
    public function __construct(private LhdnRejectionReasons $rejectionReasons, private EInvoiceGuestEmail $guestEmail, private FolioEInvoiceLock $folioEInvoiceLock) {}

    /**
     * @return array<string, mixed>
     */
    public function for(EInvoiceSubmission $submission): array
    {
        $submission->loadMissing(['property.settings', 'folio.reservation', 'arInvoice.company', 'originalSubmission', 'adjustments']);
        $timeZone = $submission->property->settings->time_zone ?? config('app.timezone');
        $at = fn (?CarbonImmutable $moment): ?string => $moment?->setTimezone($timeZone)->format('d M Y, g:i a');
        $isCreditNote = $submission->document_type === EInvoiceDocumentType::CreditNote;
        $status = $submission->status;

        return [
            'id' => $submission->id,
            'number' => $submission->internal_id,
            'kind' => $submission->is_consolidated ? 'summary' : ($isCreditNote ? 'credit_note' : 'invoice'),
            'status' => new BadgeData($status->value, $status->label(), $status->tone()),
            'amounts' => $this->amounts($submission),
            'period' => $this->period($submission),
            'buyer' => $submission->is_consolidated ? null : $this->buyer($submission->buyer_snapshot ?? []),
            'stays_included' => $submission->is_consolidated ? $submission->folios()->count() : null,
            'reservation' => $submission->folio?->reservation === null ? null : [
                'id' => $submission->folio->reservation->id,
                'reference' => $submission->folio->reservation->reference,
                'folio_id' => $submission->folio->id,
                'folio_number' => $submission->folio->number,
            ],
            'ar_invoice' => $submission->arInvoice === null ? null : [
                'id' => $submission->arInvoice->id,
                'number' => $submission->arInvoice->number,
                'company' => $submission->arInvoice->company?->name,
                'company_id' => $submission->arInvoice->company_id,
            ],
            'original' => $submission->originalSubmission === null ? null : [
                'id' => $submission->originalSubmission->id,
                'number' => $submission->originalSubmission->internal_id,
            ],
            'credited' => $this->credited($submission),
            'credit_notes' => $submission->adjustments->sortByDesc('id')->values()->map(fn (EInvoiceSubmission $note): array => [
                'id' => $note->id,
                'number' => $note->internal_id,
                'amount' => $this->amounts($note)['total'],
                'status' => new BadgeData($note->status->value, $note->status->label(), $note->status->tone()),
                'issued_at' => $at($note->created_at),
            ])->all(),
            'accepted_at' => $at($submission->validated_at),
            'cancelled_at' => $at($submission->cancelled_at),
            'cancel_reason' => $submission->cancel_reason,
            'cancel_until' => $submission->isCancellable() ? $at($submission->cancellationDeadline()) : null,
            'can_cancel' => $submission->isCancellable(),
            'can_credit_note' => ! $isCreditNote && $status === EInvoiceStatus::Valid,
            'can_check_status' => in_array($status, [EInvoiceStatus::Pending, EInvoiceStatus::Submitted], true),
            'guest_link' => $status === EInvoiceStatus::Valid ? $submission->validationUrl() : null,
            'guest_email' => $status === EInvoiceStatus::Valid && ! $submission->is_consolidated ? $this->guestEmail->for($submission) : null,
            'lhdn_reference' => $submission->uuid,
            'reasons' => $status === EInvoiceStatus::Invalid ? $this->rejectionReasons->for($submission) : [],
            'can_resubmit' => $this->canResubmit($submission),
            'can_find_tax_number' => $status === EInvoiceStatus::Invalid
                && ! $submission->is_consolidated
                && ! in_array(strtoupper((string) ($submission->buyer_snapshot['id_value'] ?? 'NA')), ['', 'NA'], true)
                && $this->rejectionReasons->concernsTaxNumber($submission),
            'history' => $this->history($submission, $at),
        ];
    }

    /**
     * One row of the e-invoice list. Expects `folio.reservation`, `arInvoice.company` and `folios_count` loaded.
     *
     * @return array<string, mixed>
     */
    public function row(EInvoiceSubmission $submission): array
    {
        $timeZone = $submission->property->settings->time_zone ?? config('app.timezone');
        $date = $submission->validated_at ?? $submission->submitted_at ?? $submission->created_at;

        return [
            'id' => $submission->id,
            'number' => $submission->internal_id,
            'kind' => $this->kind($submission),
            'billed_to' => $submission->is_consolidated ? __('General public') : (string) ($submission->buyer_snapshot['name'] ?? __('Guest')),
            'source' => match (true) {
                $submission->is_consolidated => trans_choice(':count stay|:count stays', (int) $submission->folios_count, ['count' => (int) $submission->folios_count]),
                $submission->folio?->reservation !== null => $submission->folio->reservation->reference,
                $submission->arInvoice !== null => $submission->arInvoice->number,
                default => null,
            },
            'amount' => $this->amounts($submission)['total'],
            'date' => $date?->setTimezone($timeZone)->toDateString(),
            'status' => new BadgeData($submission->status->value, $submission->status->label(), $submission->status->tone()),
        ];
    }

    /**
     * Whether staff can send this rejected stay or city ledger invoice again: nothing newer has replaced it yet.
     */
    private function canResubmit(EInvoiceSubmission $submission): bool
    {
        if ($submission->status !== EInvoiceStatus::Invalid || $submission->is_consolidated || $submission->document_type !== EInvoiceDocumentType::Invoice) {
            return false;
        }

        if ($submission->ar_invoice_id === null && $submission->folio !== null) {
            // Also covers a monthly summary that took the stay in after the rejection.
            return $this->folioEInvoiceLock->for($submission->folio) === null;
        }

        return ! EInvoiceSubmission::query()
            ->where('property_id', $submission->property_id)
            ->where('document_type', EInvoiceDocumentType::Invoice)
            ->where('ar_invoice_id', $submission->ar_invoice_id)
            ->active()
            ->exists();
    }

    /**
     * What the valid credit notes take off this e-invoice, or null when it has none.
     */
    private function credited(EInvoiceSubmission $submission): ?string
    {
        $valid = $submission->adjustments->where('document_type', EInvoiceDocumentType::CreditNote)->where('status', EInvoiceStatus::Valid);
        if ($valid->isEmpty()) {
            return null;
        }

        $sum = $valid->reduce(fn (BigDecimal $total, EInvoiceSubmission $note): BigDecimal => $total->plus($this->amounts($note)['total'] ?? '0'), BigDecimal::zero());

        return (string) $sum->toScale(2);
    }

    /**
     * @return array{value: string, label: string}
     */
    private function kind(EInvoiceSubmission $submission): array
    {
        return match (true) {
            $submission->is_consolidated => ['value' => 'summary', 'label' => __('Monthly summary')],
            $submission->document_type === EInvoiceDocumentType::CreditNote => ['value' => 'credit_note', 'label' => __('Credit note')],
            default => ['value' => 'invoice', 'label' => __('E-invoice')],
        };
    }

    /**
     * The totals LHDN holds for the document, read from what was sent.
     *
     * @return array{subtotal: string|null, tax: string|null, total: string|null}
     */
    private function amounts(EInvoiceSubmission $submission): array
    {
        $document = data_get($submission->document_payload, 'Invoice.0') ?? data_get($submission->document_payload, 'CreditNote.0');
        $money = fn (mixed $value): ?string => $value === null ? null : (string) BigDecimal::of((string) $value)->toScale(2);

        return [
            'subtotal' => $money(data_get($document, 'LegalMonetaryTotal.0.TaxExclusiveAmount.0._')),
            'tax' => $money(data_get($document, 'TaxTotal.0.TaxAmount.0._')),
            'total' => $money(data_get($document, 'LegalMonetaryTotal.0.PayableAmount.0._')),
        ];
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function period(EInvoiceSubmission $submission): ?array
    {
        $from = data_get($submission->document_payload, 'Invoice.0.InvoicePeriod.0.StartDate.0._');
        $to = data_get($submission->document_payload, 'Invoice.0.InvoicePeriod.0.EndDate.0._');

        return $from !== null && $to !== null ? ['from' => (string) $from, 'to' => (string) $to] : null;
    }

    /**
     * The buyer as staff need to see it. The ID number shows only its last four characters.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{name: string, tax_number: string|null, id_label: string|null, id_number: string|null, email: string|null, phone: string|null, address: string|null}
     */
    private function buyer(array $snapshot): array
    {
        $idValue = (string) ($snapshot['id_value'] ?? '');
        $hasId = $idValue !== '' && strtoupper($idValue) !== 'NA';
        $address = collect([
            $snapshot['address_line1'] ?? null,
            $snapshot['address_line2'] ?? null,
            trim(($snapshot['postal_code'] ?? '').' '.($snapshot['city'] ?? '')),
        ])->filter(fn (?string $part): bool => $part !== null && $part !== '' && strtoupper($part) !== 'NA')->implode(', ');

        return [
            'name' => (string) ($snapshot['name'] ?? __('Guest')),
            'tax_number' => ($snapshot['tin'] ?? '') !== '' ? (string) $snapshot['tin'] : null,
            'id_label' => $hasId ? $this->idLabel((string) ($snapshot['id_type'] ?? '')) : null,
            'id_number' => $hasId ? '•••• '.substr($idValue, -4) : null,
            'email' => ($snapshot['email'] ?? '') !== '' ? (string) $snapshot['email'] : null,
            'phone' => ($snapshot['phone'] ?? '') !== '' ? (string) $snapshot['phone'] : null,
            'address' => $address !== '' ? $address : null,
        ];
    }

    private function idLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'NRIC' => __('IC (MyKad)'),
            'PASSPORT' => __('Passport'),
            'BRN' => __('Company registration'),
            'ARMY' => __('Army ID'),
            default => __('ID'),
        };
    }

    /**
     * What happened to the document, oldest first.
     *
     * @param  callable(?CarbonImmutable): ?string  $at
     * @return list<array{label: string, at: string|null}>
     */
    private function history(EInvoiceSubmission $submission, callable $at): array
    {
        $events = [];

        if ($submission->submitted_at !== null) {
            $events[] = ['label' => __('Sent to LHDN'), 'at' => $at($submission->submitted_at), 'sort' => $submission->submitted_at];
        }

        if ($submission->validated_at !== null) {
            $events[] = ['label' => __('Accepted by LHDN'), 'at' => $at($submission->validated_at), 'sort' => $submission->validated_at];
        } elseif ($submission->status === EInvoiceStatus::Invalid) {
            $events[] = ['label' => __('Rejected by LHDN'), 'at' => $at($submission->updated_at), 'sort' => $submission->updated_at];
        }

        foreach ($submission->adjustments as $note) {
            $events[] = ['label' => __('Credit note :number issued', ['number' => $note->internal_id]), 'at' => $at($note->created_at), 'sort' => $note->created_at];
        }

        if ($submission->cancelled_at !== null) {
            $events[] = ['label' => __('Cancelled'), 'at' => $at($submission->cancelled_at), 'sort' => $submission->cancelled_at];
        }

        usort($events, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $event): array => ['label' => $event['label'], 'at' => $event['at']], $events);
    }
}
