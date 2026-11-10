<?php

namespace App\Services\Reservations;

use App\Data\Reservations\GroupAddOnPreviewData;
use App\Data\Reservations\GroupAddOnPreviewLineData;
use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Catalogues\ChargeChildPrices;
use App\Services\Catalogues\ChargeQuantityResolver;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Brick\Money\Money;

/**
 * What the group add-on would write on each picked unit, read only. It prices the rows with `ChargeQuantityResolver`
 * and `ChargeChildPrices`, as `AddUnitAddOn` does, so the totals match a save. Refusals are not checked here; the
 * save names a refused room. Units that are not Confirmed or CheckedIn are left out.
 * See docs/12-reservations/04-add-ons.md#group-add-on.
 */
class GroupAddOnPreview
{
    public function __construct(
        private ChargeQuantityResolver $quantityResolver,
        private ChargeChildPrices $childPrices,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private ReservationRoomBox $roomBox,
    ) {}

    /**
     * @param  list<int>  $unitIds
     */
    public function preview(Reservation $reservation, array $unitIds, NewAddOnData $data): GroupAddOnPreviewData
    {
        $property = $reservation->property;
        $currency = $property->settings->currency;
        $units = $reservation->units()
            ->whereIn('id', $unitIds)
            ->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value])
            ->with(['roomType', 'childAges'])
            ->orderBy('id')
            ->get();
        $item = ChargeItem::query()->where('property_id', $property->id)->active()->find($data->chargeItemId);
        $businessDate = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
        $assigned = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $units->modelKeys())));
        $default = $item?->default_amount === null ? null : Money::of($item->default_amount, $currency);
        $amount = $data->unitAmount !== null && preg_match('/^\d+(\.\d{1,2})?$/', $data->unitAmount) === 1
            ? Money::of($data->unitAmount, $currency)
            : $default;
        $isNightly = $item !== null && in_array($item->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true);
        $sum = Money::zero($currency);
        $lines = [];

        foreach ($units as $unit) {
            /** @var ReservationUnit $unit */
            $label = $unit->roomType->name.' · '.($assigned[$unit->id] ?? __('Not assigned'));
            $guests = $unit->adults + $unit->children;
            $rows = [];

            if ($item !== null && ! ($isNightly && $unit->stay_type === StayType::DayUse)) {
                $quantity = $item->charging_unit === ChargingUnit::PerItem ? max(1, $data->quantity ?? 1) : null;
                $split = $this->childPrices->split($item, array_values($unit->childAges->pluck('age')->map(fn (mixed $age): int => (int) $age)->all()), $unit->children);
                $rows = $this->quantityResolver->rows($item->charging_unit, $unit, $businessDate, $quantity, $isNightly ? null : $data->serviceDate, $split);
            }

            $total = null;

            if ($rows !== [] && $amount !== null) {
                $total = Money::zero($currency);

                foreach ($rows as $row) {
                    $total = $total->plus($this->childPrices->rowAmount($row['child_price'], $amount, $default)->multipliedBy($row['quantity']));
                }

                $sum = $sum->plus($total);
            }

            $lines[] = new GroupAddOnPreviewLineData(
                unitId: $unit->id,
                label: $label,
                guests: $guests,
                nights: $isNightly ? count(array_unique(array_map(fn (array $row): ?string => $row['service_date']?->toDateString(), $rows))) : null,
                total: $total === null ? null : (string) $total->getAmount(),
                note: $item !== null && $rows === [] ? __('Not added') : null,
            );
        }

        return new GroupAddOnPreviewData(lines: $lines, total: (string) $sum->getAmount());
    }
}
