<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioRowData;
use App\Data\Shared\BadgeData;
use App\Enums\FolioRowTag;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Works out what the night audit and the add-on posting will post, for the folio page. A night is Scheduled until its
 * `night:{unit_night_id}` line exists, and an add-on until its row has a `folio_line_id`. Nothing is stored and
 * nothing is written. Each block is a charge with its discount and tax children, placed on the folio the router
 * would choose (null while the unit has no folio). Tax is worked out with `TaxCalculator`, on the discounted amount.
 * Assumes the booker's nationality drives foreign-guest taxes, as `PostCharge` does. Expects the property's `settings` and
 * the reservation's `booker` to be loaded. See docs/13-folio/05-folio-page.md.
 */
class ScheduledFolioRows
{
    /** @var Collection<int, Tax>|null The property's taxes with the ids of their codes, read once for all rows. */
    private ?Collection $propertyTaxes = null;

    public function __construct(
        private FolioRouter $folioRouter,
        private TaxCalculator $taxCalculator,
        private NightChargeQuote $nightChargeQuote,
    ) {}

    /**
     * @param  array<int, string>  $unitLabels  Short unit label (room, or room type) by unit id.
     * @return list<array{target: int|null, unit: int, date: string, rows: list<FolioRowData>}>
     */
    public function forReservation(Property $property, Reservation $reservation, array $unitLabels): array
    {
        $currency = $property->settings?->currency->value;
        $roomCode = TransactionCode::query()
            ->where('property_id', $property->id)
            ->where('system_key', SystemTransactionCode::RoomRevenue->value)
            ->first();

        if ($currency === null || $roomCode === null) {
            return [];
        }

        $this->propertyTaxes = null;
        $units = $reservation->units()
            ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->with(['discount', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.ratePlan.inclusions.chargeItem.transactionCode', 'addOns' => fn ($addOns) => $addOns->whereNull('folio_line_id'), 'addOns.chargeItem.transactionCode'])
            ->orderBy('id')
            ->get();
        $posted = FolioLine::query()
            ->whereIn('idempotency_key', $units->flatMap(fn (ReservationUnit $unit): Collection => $unit->nights->map(fn (UnitNight $night): string => 'night:'.$night->id))->all())
            ->pluck('idempotency_key')
            ->flip();
        $resolve = $this->folioRouter->resolverFor($reservation);
        $nationality = $reservation->booker->nationality_code;
        $blocks = [];

        foreach ($units as $unit) {
            $label = $unitLabels[$unit->id] ?? null;
            $first = true;

            foreach ($unit->nights as $night) {
                $isFirst = $first;
                $first = false;

                if ($posted->has('night:'.$night->id)) {
                    continue;
                }

                $quote = $this->nightChargeQuote->quote($property, $this->propertyTaxes ??= $this->nightChargeQuote->propertyTaxes($property), $unit, $night, $isFirst, $roomCode, $nationality);

                if ($quote->isRefused()) {
                    continue;
                }

                foreach ($quote->charges as $charge) {
                    $blocks[] = [
                        'target' => $resolve($unit, $charge->code),
                        'unit' => $unit->id,
                        'date' => $night->date->toDateString(),
                        'rows' => $this->chargeRows($charge, $night, $label),
                    ];
                }
            }

            foreach ($unit->addOns as $addOn) {
                $code = $addOn->chargeItem->transactionCode;
                $date = ($addOn->service_date ?? $unit->arrival_date)->toDateString();

                $blocks[] = [
                    'target' => $resolve($unit, $code),
                    'unit' => $unit->id,
                    'date' => $date,
                    'rows' => $this->addOnRows($property, $addOn, $code, $date, $currency, $nationality, $label),
                ];
            }
        }

        usort($blocks, fn (array $a, array $b): int => [$a['date'], $a['unit']] <=> [$b['date'], $b['unit']]);

        return $blocks;
    }

    /**
     * The charge, its discounts, then its tax. Each charge is its own block, because routing is by transaction code.
     *
     * @return list<FolioRowData>
     */
    private function chargeRows(QuotedCharge $charge, UnitNight $night, ?string $label): array
    {
        $date = $night->date->toDateString();
        $overridden = $charge->key === 'night:'.$night->id && $night->override_amount !== null;
        $rows = [$this->row($charge->key, $date, $charge->description, 0, $label, $charge->lineAmount, $overridden ? [FolioRowTag::Scheduled, FolioRowTag::Overridden] : [FolioRowTag::Scheduled])];

        foreach ($charge->discounts as $discount) {
            $rows[] = $this->row($discount->key, $date, $discount->description, 1, $label, $discount->amount->negated(), [FolioRowTag::Scheduled]);
        }

        return [...$rows, ...$this->taxRows($charge->key, $date, $label, $charge->tax)];
    }

    /**
     * @return list<FolioRowData>
     */
    private function addOnRows(Property $property, UnitAddOn $addOn, TransactionCode $code, string $date, string $currency, ?string $nationality, ?string $label): array
    {
        $key = 'addon:'.$addOn->id;
        $entered = Money::of($addOn->unit_amount, $currency)->multipliedBy($addOn->quantity);
        $tax = $this->tax($property, $code, $entered, (string) $addOn->quantity, $nationality);
        $name = $addOn->chargeItem->name.($addOn->quantity > 1 ? ' ×'.$addOn->quantity : '');

        return [
            $this->row($key, $date, $name, 0, $label, $tax->isCalculated() ? $tax->netAmount : $entered, [FolioRowTag::Scheduled]),
            ...$this->taxRows($key, $date, $label, $tax),
        ];
    }

    private function tax(Property $property, TransactionCode $code, Money $entered, string $quantity, ?string $nationality): TaxCalculationResult
    {
        $this->propertyTaxes ??= $this->nightChargeQuote->propertyTaxes($property);
        $taxes = $this->propertyTaxes->filter(fn (Tax $tax): bool => $tax->applicableTransactionCodes->contains('id', $code->id));

        return $this->taxCalculator->calculateWithTaxes($property, $taxes, $entered, $quantity, $nationality);
    }

    /**
     * A refused tax (for example a missing nationality) shows no tax rows.
     *
     * @return list<FolioRowData>
     */
    private function taxRows(string $key, string $date, ?string $label, TaxCalculationResult $tax): array
    {
        return array_map(
            fn ($component): FolioRowData => $this->row($key.':tax:'.$component->taxId, $date, $component->name, 1, $label, $component->amount, [FolioRowTag::Scheduled]),
            $tax->isCalculated() ? $tax->taxes : [],
        );
    }

    /**
     * @param  list<FolioRowTag>  $tags
     */
    private function row(string $key, string $date, string $description, int $level, ?string $unit, Money $amount, array $tags): FolioRowData
    {
        $kind = match (true) {
            str_contains($key, ':tax:') => TransactionKind::Tax,
            str_ends_with($key, ':discount'), str_ends_with($key, ':stay-discount') => TransactionKind::Adjustment,
            default => TransactionKind::Charge,
        };

        return new FolioRowData($key, 'scheduled', $date, $description, $level, $unit, (string) $amount->getAmount(), array_map(BadgeData::fromFolioRowTag(...), $tags), kind: $kind);
    }
}
