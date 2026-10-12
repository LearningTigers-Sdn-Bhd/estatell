<?php

namespace App\Actions\Boats;

use App\Actions\Catalogues\CreateChargeItem;
use App\Actions\Catalogues\UpdateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBoatFare
{
    public function __construct(
        private CreateChargeItem $createChargeItem,
        private UpdateChargeItem $updateChargeItem,
    ) {}

    /**
     * Add a fare: a Transport charge item, priced per guest, never added to bookings automatically, available.
     * The charge item Actions do the work; the fare's own rules are the fixed values here.
     *
     * @param  array<string, mixed>  $attributes  Validated fare fields: `name`, `code`, `default_amount`, `allow_override`, `tax_ids`, and `child_prices` when sent.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ChargeItem
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ChargeItem {
            $chargeItem = ($this->createChargeItem)($property, $actor, [
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'category' => TransactionCategory::Transport,
            ]);

            ($this->updateChargeItem)($actor, $chargeItem, [
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'category' => TransactionCategory::Transport,
                'description' => null,
                'default_amount' => $attributes['default_amount'],
                'charging_unit' => ChargingUnit::PerGuest,
                'allow_override' => $attributes['allow_override'],
                'active' => true,
                'auto_add' => false,
                'tax_ids' => $attributes['tax_ids'],
                'child_prices' => $attributes['child_prices'] ?? null,
            ]);

            return $chargeItem->refresh();
        });
    }
}
