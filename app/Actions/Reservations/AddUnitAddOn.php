<?php

namespace App\Actions\Reservations;

use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Catalogues\ChargeQuantityResolver;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AddUnitAddOn
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private ChargeQuantityResolver $quantityResolver,
    ) {}

    /**
     * Add one charge item to a unit. The unit row is locked first, so a concurrent cancel cannot race. Every check
     * runs before the first write, so a refusal writes and logs nothing. Nightly items write one row per night on or
     * after the business date and ignore `serviceDate` and `quantity`. An undated add-on on a CheckedIn unit gets the
     * business date. See docs/12-reservations/04-add-ons.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, NewAddOnData $data): AddOnResult
    {
        return DB::transaction(function () use ($actor, $unit, $data): AddOnResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $item = ChargeItem::query()->where('property_id', $locked->property_id)->active()->find($data->chargeItemId);

            if ($item === null) {
                return AddOnResult::refused(AddOnResult::REFUSAL_CHARGE_ITEM_NOT_FOUND);
            }

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return AddOnResult::refused(AddOnResult::REFUSAL_UNIT_NOT_OPEN);
            }

            $property = $locked->property;
            $businessDate = $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
            $isNightly = in_array($item->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true);
            $serviceDate = $isNightly ? null : $data->serviceDate?->startOfDay();

            if ($serviceDate !== null) {
                if ($serviceDate->lt($locked->arrival_date) || $serviceDate->gt($locked->departure_date)) {
                    return AddOnResult::refused(AddOnResult::REFUSAL_SERVICE_DATE_OUTSIDE_STAY);
                }

                if ($serviceDate->lt($businessDate)) {
                    return AddOnResult::refused(AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST);
                }
            }

            $quantity = $item->charging_unit === ChargingUnit::PerItem ? $data->quantity ?? 1 : null;

            if ($quantity !== null && ($quantity < 1 || $quantity > 999)) {
                return AddOnResult::refused(AddOnResult::REFUSAL_QUANTITY_INVALID);
            }

            if ($data->unitAmount !== null && preg_match('/^\d+(\.\d{1,2})?$/', $data->unitAmount) !== 1) {
                return AddOnResult::refused(AddOnResult::REFUSAL_AMOUNT_INVALID);
            }

            $currency = $property->settings?->currency->value
                ?? throw new InvalidArgumentException('The property needs settings with a currency to add an add-on.');
            $default = $item->default_amount === null ? null : Money::of($item->default_amount, $currency);
            $amount = $data->unitAmount === null ? null : Money::of($data->unitAmount, $currency);

            if ($amount === null) {
                if ($default === null) {
                    return AddOnResult::refused(AddOnResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
                }

                $amount = $default;
            } elseif ($default !== null && ! $amount->isEqualTo($default) && ! $item->allow_override) {
                return AddOnResult::refused(AddOnResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED);
            }

            if (! $isNightly && $serviceDate === null && $locked->status === UnitStatus::CheckedIn) {
                $serviceDate = $businessDate;
            }

            $rows = $this->quantityResolver->rows($item->charging_unit, $locked, $businessDate, $quantity, $serviceDate);

            if ($rows === []) {
                return AddOnResult::refused(AddOnResult::REFUSAL_SERVICE_DATE_IN_PAST);
            }

            $addOns = array_map(fn (array $row): UnitAddOn => $locked->addOns()->create([
                'charge_item_id' => $item->id,
                'quantity' => $row['quantity'],
                'unit_amount' => (string) $amount->getAmount(),
                'service_date' => $row['service_date']?->toDateString(),
            ]), $rows);

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.add_on_added')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'charge_item_id' => $item->id,
                    'rows' => count($addOns),
                    'quantity' => $rows[0]['quantity'],
                    'unit_amount' => (string) $amount->getAmount(),
                    'service_date' => $rows[0]['service_date']?->toDateString(),
                    'amount_overridden' => $default === null || ! $amount->isEqualTo($default),
                ])
                ->log('Add-on added to unit');

            return AddOnResult::created($addOns);
        });
    }
}
