<?php

namespace App\Services\FrontDesk;

use App\Enums\PermissionEnum;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Reservations\UnitNationality;
use Brick\Money\Money;

/**
 * The charge item a damage charge at check-out posts to: the property's `damage_charge_item_id`, when it is set and
 * the item is still active. The check-out Dialog offers the damage charge only to a user with `folio.post-charge`.
 * It reads only. See docs/18-front-desk/01-check-in-and-check-out.md#keep-the-deposit-for-damages.
 */
class DamageChargeItem
{
    public function __construct(
        private TaxCalculator $taxCalculator,
        private UnitNationality $unitNationality,
    ) {}

    public function for(Property $property): ?ChargeItem
    {
        $id = $property->loadMissing('settings')->settings?->damage_charge_item_id;

        return $id === null ? null : ChargeItem::query()->where('property_id', $property->id)->active()->find($id);
    }

    /**
     * The item, when this user may post it.
     */
    public function offeredTo(Property $property, User $user): ?ChargeItem
    {
        return $user->can(PermissionEnum::FolioPostCharge->value) ? $this->for($property) : null;
    }

    /**
     * What a damage charge of `$amount` adds to the unit folio with its tax, as `PostCharge` will post it, or null when
     * the tax is refused (the check-out then shows the refusal).
     */
    public function total(ChargeItem $item, ReservationUnit $unit, string $amount): ?string
    {
        $property = $unit->property;
        $currency = $property->loadMissing('settings')->settings->currency->getCurrencyCode();
        $tax = $this->taxCalculator->calculate($property, $item->transactionCode, Money::of($amount, $currency), '1', $this->unitNationality->for($unit));

        return $tax->isCalculated() ? (string) $tax->grossAmount->getAmount() : null;
    }
}
