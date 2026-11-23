<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\Event;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Services\Catalogues\BankAccountLines;
use App\Services\Folio\DateRange;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\DocumentRow;
use App\Services\Folio\FolioDocumentRows;
use App\Services\Folio\FolioPaymentRows;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\Property\AmountInWords;
use App\Services\Reservations\BookingSummaryDocument;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The booking summary of an event: the same proforma as a reservation's (`BookingSummary`), with the event number, its
 * dates and payer, a Facilities list in place of Rooms, and the charges of its folios that are not Voided (posted lines,
 * then the facility bookings not posted yet). No cancellation policy: the property's policy is written for rooms. It
 * writes nothing. See docs/33-facilities/03-events-and-event-folios.md#booking-summary.
 */
class EventBookingSummary
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private DocumentLines $documentLines,
        private FolioDocumentRows $documentRows,
        private FolioPaymentRows $paymentRows,
        private AmountInWords $amountInWords,
        private BankAccountLines $bankAccountLines,
    ) {}

    /**
     * @param  bool  $withPrices  False asks for the print without prices. An event with no company payer ignores it.
     */
    public function for(Property $property, Event $event, bool $withPrices = true): BookingSummaryDocument
    {
        $property->loadMissing(['settings', 'printSetting']);
        $event->loadMissing(['payerGuest', 'payerCompany']);
        $settings = $property->printSetting;
        $timeZone = $property->settings->time_zone;
        $showPrices = $withPrices || $event->payer_company_id === null;
        $folios = $this->folios($property, $event);

        $lines = null;
        $payments = [];
        $paid = $billed = $balance = $words = null;

        if ($showPrices) {
            $lines = $this->documentLines->group($this->rows($property, $folios), $settings);
            [$payments, $paid, $billed] = $this->paymentRows->for($property, $folios, $event->payerCompany?->name);

            if ($lines->groups !== [] || $lines->taxes !== [] || $payments !== []) {
                $balance = (string) BigDecimal::of($lines->total)->minus($paid)->minus($billed)->toScale(2);
                $words = $settings->show_amount_in_words ? $this->amountInWords->for($balance, $property->settings->currency) : null;
            }
        }

        return new BookingSummaryDocument(
            reference: $event->number,
            asAt: now()->setTimezone($timeZone),
            currency: $property->settings->currency,
            stamp: $event->status === EventStatus::Cancelled ? __('CANCELLED') : null,
            showPrices: $showPrices,
            strip: $this->strip($event, $timeZone),
            rooms: $this->facilityLines($event),
            lines: $lines,
            payments: $payments,
            paid: $paid,
            billed: $billed,
            balanceDue: $balance,
            balanceInWords: $words,
            cancellationPolicy: [],
            paymentDetails: $showPrices && filled($settings->bank_details) ? $settings->bank_details : null,
            bankAccounts: $showPrices ? $this->bankAccountLines->for($property) : [],
            roomsHeading: 'Facilities',
        );
    }

    /**
     * The posted lines of the folios, then the Scheduled facility bookings of the Open ones. An event has no units, so
     * every row prints under Other charges.
     *
     * @param  EloquentCollection<int, Folio>  $folios
     * @return list<DocumentRow>
     */
    private function rows(Property $property, EloquentCollection $folios): array
    {
        $lines = FolioLine::query()->whereIn('folio_id', $folios->modelKeys())->orderBy('posting_date')->orderBy('id')->get();
        $entries = [];

        foreach ($this->documentRows->for($property, $lines, new EloquentCollection, [], false) as $row) {
            $entries[] = ['sort' => [$row->date->toDateString(), 0, count($entries)], 'row' => $row];
        }

        foreach ($this->scheduledRows->forFacilityBookings($property, array_values($folios->where('status', FolioStatus::Open)->modelKeys())) as $block) {
            foreach ($block['rows'] as $row) {
                $entries[] = [
                    'sort' => [$block['date'], 1, count($entries)],
                    'row' => new DocumentRow($row->kind ?? TransactionKind::Charge, $row->description, CarbonImmutable::parse($block['date']), 1, $row->amount, $row->amount),
                ];
            }
        }

        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $entry): DocumentRow => $entry['row'], $entries);
    }

    /**
     * @return EloquentCollection<int, Folio>
     */
    private function folios(Property $property, Event $event): EloquentCollection
    {
        return Folio::query()
            ->where('property_id', $property->id)
            ->where('event_id', $event->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->with('payerCompany:id,name')
            ->get()
            ->keyBy('id');
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function strip(Event $event, string $timeZone): array
    {
        $cells = [
            [__('Bill to'), $event->payerCompany->name ?? $event->payerGuest->full_name ?? ''],
            [__('Event'), $event->name],
            [__('Dates'), DateRange::long($event->starts_on, $event->ends_on)],
            [__('Booked on'), $event->created_at?->setTimezone($timeZone)->format('d M Y') ?? ''],
        ];

        return array_values(array_map(
            fn (array $cell): array => ['label' => $cell[0], 'value' => $cell[1]],
            array_filter($cells, fn (array $cell): bool => filled($cell[1])),
        ));
    }

    /**
     * One line per facility booking that is not Cancelled: "11 Dec · Riverside Hall · 18:00–23:00 · 80 people", with
     * "Tentative" at the end of a Tentative one.
     *
     * @return list<string>
     */
    private function facilityLines(Event $event): array
    {
        return array_values(FacilityBooking::query()
            ->where('event_id', $event->id)
            ->live()
            ->with('facility:id,name,booked_by')
            ->ordered()
            ->get()
            ->map(fn (FacilityBooking $booking): string => implode(' · ', array_filter([
                $booking->booking_date->format('j M'),
                $booking->facility->name,
                substr($booking->starts_at, 0, 5).'–'.substr($booking->ends_at, 0, 5),
                $booking->quantity.' '.$booking->facility->booked_by->unit($booking->quantity),
                $booking->status === FacilityBookingStatus::Tentative ? $booking->status->label() : null,
            ])))
            ->all());
    }
}
