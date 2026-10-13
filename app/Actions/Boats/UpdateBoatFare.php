<?php

namespace App\Actions\Boats;

use App\Actions\Catalogues\UpdateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\User;
use App\Services\Boats\BoatFareUsage;
use Illuminate\Support\Facades\DB;

class UpdateBoatFare
{
    public function __construct(
        private UpdateChargeItem $updateChargeItem,
        private BoatFareUsage $fareUsage,
    ) {}

    /**
     * Save a fare. The code and description stay as they are; the fare's own rules (Transport, per guest, no
     * automatic add) are fixed here. Without `child_prices` the child rows are kept, as the Available switch sends none.
     *
     * Refused, and nothing is saved or logged, when `active` is turned off while an available departure uses the
     * fare. The row lock holds the departures that use it, so a departure cannot switch on in between. Returns false
     * for that refusal (`fare_in_use`).
     *
     * @param  array<string, mixed>  $attributes  Validated fields: `name`, `default_amount`, `allow_override`, `tax_ids`, and `active` and `child_prices` when sent. Without `active` the fare keeps its state.
     */
    public function __invoke(User $actor, ChargeItem $chargeItem, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $chargeItem, $attributes): bool {
            $chargeItem = ChargeItem::query()->lockForUpdate()->findOrFail($chargeItem->id);

            $active = $attributes['active'] ?? $chargeItem->active;

            if (! $active && $this->fareUsage->isUsedByAvailableDeparture($chargeItem, lock: true)) {
                return false;
            }

            return ($this->updateChargeItem)($actor, $chargeItem, [
                'name' => $attributes['name'],
                'code' => $chargeItem->transactionCode->code,
                'category' => TransactionCategory::Transport,
                'description' => $chargeItem->description,
                'default_amount' => $attributes['default_amount'],
                'charging_unit' => ChargingUnit::PerGuest,
                'allow_override' => $attributes['allow_override'],
                'active' => $active,
                'auto_add' => false,
                'tax_ids' => $attributes['tax_ids'],
                'child_prices' => $attributes['child_prices'] ?? null,
            ]);
        });
    }
}
