<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioRowData;
use App\Data\Shared\BadgeData;
use App\Enums\DiscountType;
use App\Enums\FolioRowTag;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Discount;
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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
            ->with(['discount', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.ratePlan', 'addOns' => fn ($addOns) => $addOns->whereNull('folio_line_id'), 'addOns.chargeItem.transactionCode'])
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

                $blocks[] = [
                    'target' => $resolve($unit, $roomCode),
                    'unit' => $unit->id,
                    'date' => $night->date->toDateString(),
                    'rows' => $this->nightRows($property, $unit, $night, $roomCode, $currency, $nationality, $label, $isFirst),
                ];
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
     * Room, then the stay discount and the booking-time discount, then tax on what is left. A night with a price
     * override is one row with no discount children, because the override replaces the whole sum.
     *
     * @return list<FolioRowData>
     */
    private function nightRows(Property $property, ReservationUnit $unit, UnitNight $night, TransactionCode $code, string $currency, ?string $nationality, ?string $label, bool $firstNight): array
    {
        $key = 'night:'.$night->id;
        $date = $night->date->toDateString();
        $overridden = $night->override_amount !== null;
        $net = Money::of($night->amount, $currency);
        $gross = $overridden ? $net : Money::of($night->base_amount, $currency)->plus($night->extra_amount)->plus($night->supplement_amount);
        $stayDiscount = $overridden ? Money::zero($currency) : Money::of($night->discount_amount, $currency);
        $bookingDiscount = ($overridden || $unit->discount === null) ? Money::zero($currency) : $this->bookingDiscount($unit->discount, $net, $firstNight, $currency);
        $entered = $net->minus($bookingDiscount);
        $tax = $this->tax($property, $code, $entered, '1', $nationality);
        $shift = $tax->isCalculated() ? $entered->minus($tax->netAmount) : Money::zero($currency);

        $rows = [$this->row($key, $date, 'Room · '.$night->ratePlan->name, 0, $label, $gross->minus($shift), $overridden ? [FolioRowTag::Scheduled, FolioRowTag::Overridden] : [FolioRowTag::Scheduled])];

        if ($stayDiscount->isPositive()) {
            $rows[] = $this->row($key.':stay-discount', $date, 'Stay discount', 1, $label, $stayDiscount->negated(), [FolioRowTag::Scheduled]);
        }

        if ($bookingDiscount->isPositive()) {
            $rows[] = $this->row($key.':discount', $date, $unit->discount->name, 1, $label, $bookingDiscount->negated(), [FolioRowTag::Scheduled]);
        }

        return [...$rows, ...$this->taxRows($key, $date, $label, $tax)];
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

    /**
     * A Percent discount takes its share of every night. A Fixed discount is taken once per stay, from the first
     * night, and never more than that night. An Open discount has no amount at booking time.
     */
    private function bookingDiscount(Discount $discount, Money $net, bool $firstNight, string $currency): Money
    {
        $zero = Money::zero($currency);

        return match ($discount->type) {
            DiscountType::Percent => $net->multipliedBy(BigDecimal::of((string) $discount->value)->dividedBy(100, 8), RoundingMode::HalfUp),
            DiscountType::Fixed => $firstNight ? Money::min(Money::of((string) $discount->value, $currency), $net) : $zero,
            DiscountType::Open => $zero,
        };
    }

    private function tax(Property $property, TransactionCode $code, Money $entered, string $quantity, ?string $nationality): TaxCalculationResult
    {
        $this->propertyTaxes ??= $property->taxes()->with('applicableTransactionCodes:id')->get();
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
