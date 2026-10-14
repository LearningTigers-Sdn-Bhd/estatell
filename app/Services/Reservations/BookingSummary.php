<?php

namespace App\Services\Reservations;

use App\Data\Folio\FolioRowData;
use App\Enums\FolioStatus;
use App\Enums\Locale;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomRevenuePolicy;
use App\Models\UnitAddOn;
use App\Services\Folio\DateRange;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\DocumentRow;
use App\Services\Folio\FolioDocumentRows;
use App\Services\Folio\FolioPaymentRows;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\Property\AmountInWords;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out the booking summary of one reservation: the info strip, the rooms, the charge rows (posted lines of every
 * folio that is not Voided, then the Scheduled rows), the payments, the balance due and the footer texts. The posted rows come from
 * `FolioDocumentRows` (without tax numbers), the Scheduled rows from `ScheduledFolioRows`. It does not use `FolioStatement`. It writes nothing. Without prices (only for a
 * reservation with a company) it leaves out every amount. See docs/12-reservations/08-booking-summary.md.
 */
class BookingSummary
{
    public function __construct(
        private ScheduledFolioRows $scheduledRows,
        private DocumentLines $documentLines,
        private FolioDocumentRows $documentRows,
        private FolioPaymentRows $paymentRows,
        private AmountInWords $amountInWords,
        private StayLabel $stayLabel,
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

        $units = $reservation->units()->with(['roomType', 'ratePlan', 'guests.guest', 'dayUse'])->orderBy('id')->get();
        $places = $this->documentRows->places($property, $units);
        $live = $units->filter(fn (ReservationUnit $unit): bool => $unit->status->holdsInventory());
        $dated = $live->isEmpty() ? $units : $live;
        $from = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString()));
        $to = $dated->isEmpty() ? null : CarbonImmutable::parse($dated->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString()));

        $lines = null;
        $payments = [];
        $paid = $billed = $balance = $words = null;

        if ($showPrices) {
            $lines = $this->documentLines->group($this->rows($property, $reservation, $units, $places), $settings);
            [$payments, $paid, $billed] = $this->paymentRows->for($property, $this->folios($property, $reservation), $reservation->company?->name);

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
            strip: $this->strip($reservation, $from, $to, $timeZone, $this->stayLabel->forUnits($dated)),
            rooms: $units->map(fn (ReservationUnit $unit): string => $this->roomLine($unit, $places[$unit->id]['place'], $from, $to, $showPrices))->values()->all(),
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
     * @param  Collection<int, ReservationUnit>  $units
     * @param  array<int, array{place: ?string, room: string, heading: string}>  $places
     * @return list<DocumentRow>
     */
    private function rows(Property $property, Reservation $reservation, Collection $units, array $places): array
    {
        $blocks = $this->scheduledRows->forReservation($property, $reservation, []);
        $lines = FolioLine::query()->whereIn('folio_id', $this->folios($property, $reservation)->modelKeys())->orderBy('posting_date')->orderBy('id')->get();
        $addOns = UnitAddOn::query()->whereIn('reservation_unit_id', $units->modelKeys())->with('chargeItem')->get()->keyBy('id');
        $order = array_flip($units->modelKeys());
        $entries = [];

        foreach ($this->documentRows->for($property, $lines, $units, $places, false) as $row) {
            $entries[] = ['sort' => [$order[$row->unitId] ?? PHP_INT_MAX, $row->date->toDateString(), 0, count($entries)], 'row' => $row];
        }

        foreach ($blocks as $block) {
            $head = $block['rows'][0]->key;
            $nightly = str_starts_with($head, 'night:') || (str_starts_with($head, 'addon:') && FolioDocumentRows::isNightlyAddOn($addOns->get((int) substr($head, 6))));

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
     * @return list<array{label: string, value: string}>
     */
    private function strip(Reservation $reservation, ?CarbonImmutable $from, ?CarbonImmutable $to, string $timeZone, ?string $dayUseLabel): array
    {
        $cells = [
            [__('Bill to'), $reservation->company->name ?? $reservation->booker->full_name],
            [__('Stay'), $from === null || $to === null ? '' : ($dayUseLabel !== null ? $from->format('d M Y').' · '.$dayUseLabel : DateRange::long($from, $to).' · '.trans_choice(':count night|:count nights', (int) $from->diffInDays($to)))],
            [__('Booked on'), $reservation->created_at->setTimezone($timeZone)->format('d M Y')],
            [__('Your ref'), trim((string) $reservation->external_reference)],
        ];

        return array_values(array_map(
            fn (array $cell): array => ['label' => $cell[0], 'value' => $cell[1]],
            array_filter($cells, fn (array $cell): bool => filled($cell[1])),
        ));
    }

    private function roomLine(ReservationUnit $unit, ?string $place, ?CarbonImmutable $from, ?CarbonImmutable $to, bool $showPrices): string
    {
        $guests = array_filter([
            $unit->guests->firstWhere('is_primary', true)?->guest?->full_name,
            trans_choice(':count adult|:count adults', $unit->adults).($unit->children > 0 ? ', '.trans_choice(':count child|:count children', $unit->children) : ''),
        ], filled(...));
        $dates = $from !== null && $to !== null && ! ($unit->arrival_date->isSameDay($from) && $unit->departure_date->isSameDay($to))
            ? DateRange::long(CarbonImmutable::parse($unit->arrival_date->toDateString()), CarbonImmutable::parse($unit->departure_date->toDateString()))
            : null;

        return implode(' · ', array_filter([$place, $unit->roomType->name, $showPrices ? $unit->ratePlan->name : null, implode(', ', $guests), $dates, $unit->status->holdsInventory() ? null : $unit->status->label()], filled(...)));
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
