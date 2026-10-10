<?php

namespace App\Services\Reservations;

use App\Data\Folio\FolioRowData;
use App\Enums\ArInvoiceType;
use App\Enums\ChargingUnit;
use App\Enums\FolioStatus;
use App\Enums\Locale;
use App\Enums\ReservationStatus;
use App\Enums\SellUnit;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\DocumentRow;
use App\Services\Folio\LineSourceUnits;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\AmountInWords;
use App\Services\Property\PropertyToday;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out the booking summary of one reservation: the info strip, the rooms, the charge rows (posted lines of every
 * folio that is not Voided, then the Scheduled rows), the payments, the balance due and the footer texts. It builds
 * the grouper's `DocumentRow`s itself and does not use `FolioStatement`. It writes nothing. Without prices (only for a
 * reservation with a company) it leaves out every amount. See docs/12-reservations/08-booking-summary.md.
 */
class BookingSummary
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private DocumentLines $documentLines,
        private LineSourceUnits $lineSourceUnits,
        private ReservationRoomBox $roomBox,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private AmountInWords $amountInWords,
    ) {}

    /**
     * @param  bool  $withPrices  False asks for the print without prices. A reservation with no company ignores it.
     */
    public function for(Property $property, Reservation $reservation, bool $withPrices = true): BookingSummaryDocument
    {
        $property->loadMissing(['settings', 'printSetting']);
        $reservation->loadMissing(['booker', 'company']);
        $settings = $property->printSetting;
        $timeZone = $property->settings->time_zone;
        $showPrices = $withPrices || $reservation->company_id === null;

        $units = $reservation->units()->with(['roomType', 'ratePlan', 'guests.guest'])->orderBy('id')->get();
        $places = $this->places($property, $units);
        $live = $units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory());
        $dated = $live->isEmpty() ? $units : $live;
        $from = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()));
        $to = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()));

        $lines = null;
        $payments = [];
        $paid = $billed = $balance = $words = null;

        if ($showPrices) {
            $lines = $this->documentLines->group($this->rows($property, $reservation, $units, $places), $settings);
            [$payments, $paid, $billed] = $this->payments($property, $reservation);

            if ($lines->groups !== [] || $lines->taxes !== [] || $payments !== []) {
                $balance = (string) BigDecimal::of($lines->total)->minus($paid)->minus($billed)->toScale(2);
                $words = $settings->show_amount_in_words ? $this->amountInWords->for($balance, $property->settings->currency) : null;
            }
        }

        return new BookingSummaryDocument(
            reference: $reservation->reference,
            asAt: now()->setTimezone($timeZone),
            currency: $property->settings->currency,
            stamp: $this->stamp($reservation, $units),
            showPrices: $showPrices,
            strip: $this->strip($reservation, $from, $to, $timeZone),
            rooms: $units->map(fn (ReservationUnit $unit): string => $this->roomLine($unit, $places[$unit->id]['place'], $from, $to))->values()->all(),
            lines: $lines,
            payments: $payments,
            paid: $paid,
            billed: $billed,
            balanceDue: $balance,
            balanceInWords: $words,
            cancellationPolicy: $settings->show_cancellation_policy ? $this->policyTexts($property) : [],
            paymentDetails: $showPrices && filled($settings->bank_details) ? $settings->bank_details : null,
        );
    }

    /**
     * CANCELLED for a cancelled reservation, NO-SHOW when every unit is a no-show, else none.
     *
     * @param  Collection<int, ReservationUnit>  $units
     */
    private function stamp(Reservation $reservation, Collection $units): ?string
    {
        if ($reservation->status === ReservationStatus::Cancelled) {
            return __('CANCELLED');
        }

        return $units->isNotEmpty() && $units->every(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::NoShow) ? __('NO-SHOW') : null;
    }

    /**
     * Each unit's place ("Room 103", or the room type while none is assigned), its Room column label and its group
     * heading. The room is tonight's, else the first night's.
     *
     * @param  Collection<int, ReservationUnit>  $units
     * @return array<int, array{place: ?string, room: string, heading: string}> By unit id
     */
    private function places(Property $property, Collection $units): array
    {
        $ids = $units->modelKeys();
        $tonight = $this->roomBox->assignmentsForUnitsOn($ids, ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->toDateString());
        $first = $this->roomBox->assignmentsForUnits($ids);
        $places = [];

        foreach ($units as $unit) {
            $assigned = $tonight[$unit->id] ?? $first[$unit->id] ?? null;
            $place = $assigned === null ? null : ($unit->roomType->sell_unit === SellUnit::Bed ? __('Bed :label', ['label' => $assigned]) : __('Room :number', ['number' => $assigned]));
            $suffix = $unit->status->holdsInventory() ? '' : ' ('.$unit->status->label().')';
            $primary = $unit->guests->firstWhere('is_primary', true)?->guest?->full_name;

            $places[$unit->id] = [
                'place' => $place,
                'room' => $assigned ?? $unit->roomType->name,
                'heading' => implode(' · ', array_filter([($place ?? $unit->roomType->name).$suffix, $primary], filled(...))),
            ];
        }

        return $places;
    }

    /**
     * @param  Collection<int, ReservationUnit>  $units
     * @param  array<int, array{place: ?string, room: string, heading: string}>  $places
     * @return list<DocumentRow>
     */
    private function rows(Property $property, Reservation $reservation, Collection $units, array $places): array
    {
        $blocks = $this->scheduledRows->forReservation($property, $reservation, []);
        $folioIds = $this->folios($property, $reservation)->modelKeys();
        $lines = FolioLine::query()->whereIn('folio_id', $folioIds)->orderBy('posting_date')->orderBy('id')->get();
        $byId = $lines->keyBy('id');
        $sourceUnits = $this->lineSourceUnits->for($lines);
        $addOns = UnitAddOn::query()->whereIn('reservation_unit_id', $units->modelKeys())->with('chargeItem')->get()->keyBy('id');
        $nightMorph = (new UnitNight)->getMorphClass();
        $addOnMorph = (new UnitAddOn)->getMorphClass();
        $order = array_flip($units->modelKeys());
        $nightlyAddOn = fn (?UnitAddOn $addOn): bool => in_array($addOn?->chargeItem->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true);

        $unitOf = function (FolioLine $line) use (&$unitOf, $byId, $sourceUnits): ?int {
            $own = $line->source_id === null ? null : ($sourceUnits[$line->source_type.':'.$line->source_id] ?? null);

            if ($own !== null || $line->parent_line_id === null || ! $byId->has($line->parent_line_id)) {
                return $own;
            }

            return $unitOf($byId[$line->parent_line_id]);
        };
        $nightlyOf = function (FolioLine $line) use (&$nightlyOf, $byId, $addOns, $nightMorph, $addOnMorph, $nightlyAddOn): bool {
            if ($line->parent_line_id !== null && $byId->has($line->parent_line_id)) {
                return $nightlyOf($byId[$line->parent_line_id]);
            }

            return match ($line->source_type) {
                $nightMorph => true,
                $addOnMorph => $nightlyAddOn($addOns->get($line->source_id)),
                default => false,
            };
        };

        $entries = [];

        foreach ($lines as $line) {
            if ($line->kind === TransactionKind::Payment) {
                continue;
            }

            $unitId = $unitOf($line);
            $entries[] = [
                'sort' => [$order[$unitId] ?? PHP_INT_MAX, $line->posting_date->toDateString(), 0, count($entries)],
                'row' => new DocumentRow(
                    $line->kind,
                    $line->description,
                    CarbonImmutable::parse($line->posting_date->toDateString()),
                    $line->quantity,
                    $line->quantity === 1 ? $line->amount : $line->unit_amount,
                    $line->amount,
                    $unitId,
                    $unitId === null ? null : ($places[$unitId]['heading'] ?? null),
                    $line->id,
                    $line->reversal_of_id,
                    $nightlyOf($line),
                    $unitId === null ? null : ($places[$unitId]['room'] ?? null),
                ),
            ];
        }

        foreach ($blocks as $block) {
            $head = $block['rows'][0]->key;
            $nightly = str_starts_with($head, 'night:') || (str_starts_with($head, 'addon:') && $nightlyAddOn($addOns->get((int) substr($head, 6))));

            foreach ($block['rows'] as $row) {
                /** @var FolioRowData $row */
                $unitId = $block['unit'];
                $entries[] = [
                    'sort' => [$order[$unitId] ?? PHP_INT_MAX, $block['date'], 1, count($entries)],
                    'row' => new DocumentRow(
                        $row->kind ?? TransactionKind::Charge,
                        $row->description,
                        CarbonImmutable::parse($block['date']),
                        1,
                        $row->amount,
                        $row->amount,
                        $unitId,
                        $places[$unitId]['heading'] ?? null,
                        nightly: $nightly,
                        room: $places[$unitId]['room'] ?? null,
                    ),
                ];
            }
        }

        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $entry): DocumentRow => $entry['row'], $entries);
    }

    /**
     * @return Collection<int, Folio>
     */
    private function folios(Property $property, Reservation $reservation): Collection
    {
        return Folio::query()
            ->where('property_id', $property->id)
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->with('payerCompany:id,name')
            ->get()
            ->keyBy('id');
    }

    /**
     * The payment lines of the folios that are not Voided, with paid (net of refunds, without the city ledger line) and
     * billed to company, as the reservation list counts them.
     *
     * @return array{0: list<BookingSummaryPayment>, 1: string, 2: string}
     */
    private function payments(Property $property, Reservation $reservation): array
    {
        $folios = $this->folios($property, $reservation);
        $lines = FolioLine::query()
            ->whereIn('folio_id', $folios->modelKeys())
            ->where('kind', TransactionKind::Payment)
            ->with('transactionCode:id,system_key')
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();
        $payments = Payment::query()->whereIn('folio_id', $folios->modelKeys())->with('paymentMethod:id,name')->get()->keyBy('folio_line_id');
        $invoices = ArInvoice::query()
            ->where('property_id', $property->id)
            ->whereIn('folio_id', $folios->modelKeys())
            ->where('type', ArInvoiceType::Invoice)
            ->orderBy('id')
            ->pluck('number', 'folio_id');
        $paid = BigDecimal::zero();
        $billed = BigDecimal::zero();
        $rows = [];

        foreach ($lines as $line) {
            $payment = $payments->get($line->id);
            $method = $payment?->paymentMethod->name ?? $line->description;
            $date = CarbonImmutable::parse($line->posting_date->toDateString());

            if ($line->transactionCode?->system_key === SystemTransactionCode::DirectBillPayment->value) {
                $billed = $billed->minus($line->amount);
                $company = $folios[$line->folio_id]->payerCompany?->name ?? $reservation->company?->name;
                $invoice = $invoices->get($line->folio_id);
                $text = $company === null ? $line->description : __('Billed to :company', ['company' => $company]);
                $rows[] = new BookingSummaryPayment($date, null, $invoice === null ? $text : $text.', '.__('invoice :number', ['number' => $invoice]), $line->amount);

                continue;
            }

            $paid = $paid->minus($line->amount);
            $rows[] = new BookingSummaryPayment($date, $payment?->receipt_number, $payment?->refund_of_id === null ? $method : __('Refund').' · '.$method, $line->amount);
        }

        return [$rows, (string) $paid->toScale(2), (string) $billed->toScale(2)];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function strip(Reservation $reservation, ?CarbonImmutable $from, ?CarbonImmutable $to, string $timeZone): array
    {
        $cells = [
            [__('Bill to'), $reservation->company?->name ?? $reservation->booker->full_name],
            [__('Stay'), $from === null || $to === null ? '' : $this->range($from, $to).' · '.trans_choice(':count night|:count nights', (int) $from->diffInDays($to))],
            [__('Booked on'), $reservation->created_at->setTimezone($timeZone)->format('d M Y')],
            [__('Your ref'), trim((string) $reservation->external_reference)],
        ];

        return array_values(array_map(
            fn (array $cell): array => ['label' => $cell[0], 'value' => $cell[1]],
            array_filter($cells, fn (array $cell): bool => filled($cell[1])),
        ));
    }

    /**
     * "12–15 Oct 2026", "28 Oct – 2 Nov 2026" or, across years, both full dates.
     */
    private function range(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameMonth($to)) {
            return $from->format('j').'–'.$to->format('j M Y');
        }

        return $from->isSameYear($to) ? $from->format('j M').' – '.$to->format('j M Y') : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    private function roomLine(ReservationUnit $unit, ?string $place, ?CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        $guests = array_filter([
            $unit->guests->firstWhere('is_primary', true)?->guest?->full_name,
            trans_choice(':count adult|:count adults', $unit->adults).($unit->children > 0 ? ', '.trans_choice(':count child|:count children', $unit->children) : ''),
        ], filled(...));
        $dates = $from !== null && $to !== null && ! ($unit->arrival_date->isSameDay($from) && $unit->departure_date->isSameDay($to))
            ? $this->range(CarbonImmutable::parse($unit->arrival_date->toDateString()), CarbonImmutable::parse($unit->departure_date->toDateString()))
            : null;

        return implode(' · ', array_filter([$place, $unit->roomType->name, $unit->ratePlan->name, implode(', ', $guests), $dates, $unit->status->holdsInventory() ? null : $unit->status->label()], filled(...)));
    }

    /**
     * The Cancellation and No-show policy texts, in Malay when the property default is Malay and that text is set.
     *
     * @return list<string>
     */
    private function policyTexts(Property $property): array
    {
        $policies = RoomRevenuePolicy::query()
            ->where('property_id', $property->id)
            ->whereIn('code', [SystemTransactionCode::CancellationRevenue, SystemTransactionCode::NoShowRevenue])
            ->get()
            ->keyBy(fn (RoomRevenuePolicy $policy): string => $policy->code->value);
        $malay = $property->settings->default_locale === Locale::Malay;
        $texts = [];

        foreach ([SystemTransactionCode::CancellationRevenue, SystemTransactionCode::NoShowRevenue] as $code) {
            $policy = $policies->get($code->value);

            if ($policy === null) {
                continue;
            }

            $text = trim($malay && filled(trim($policy->text_ms)) ? $policy->text_ms : $policy->text_en);

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
