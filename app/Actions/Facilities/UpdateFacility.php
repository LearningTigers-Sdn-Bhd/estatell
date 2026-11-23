<?php

namespace App\Actions\Facilities;

use App\Actions\Catalogues\UpdateChargeItem;
use App\Enums\ChargingUnit;
use App\Enums\FacilitySharing;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateFacility
{
    public function __construct(private UpdateChargeItem $updateChargeItem) {}

    /**
     * Save a facility and its charge item in one transaction. The charge item follows the name, the price (null is
     * "Charge by hand"), the taxes and whether the facility is on. Its code and description stay as they are. Without
     * `tax_ids` (the Available switch) the taxes stay too. Saving a
     * facility as Private deletes its sessions. Logs `facility.updated` with the changed fields, or nothing when
     * nothing changed. See docs/33-facilities/02-facility-settings.md.
     *
     * @param  array{name: string, sharing: string, capacity: int, capacity_unit: string, default_amount: ?string, rate_basis: string, active: bool, tax_ids?: ?list<int>}  $attributes
     */
    public function __invoke(User $actor, Facility $facility, array $attributes): Facility
    {
        return DB::transaction(function () use ($actor, $facility, $attributes): Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            $chargeItem = ChargeItem::query()->with('transactionCode.applicableTaxes')->findOrFail($facility->charge_item_id);
            $amountBefore = $chargeItem->default_amount;
            $taxIdsBefore = collect($chargeItem->transactionCode->applicableTaxes->modelKeys())->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $taxIds = isset($attributes['tax_ids']) ? collect($attributes['tax_ids'])->sort()->values()->all() : $taxIdsBefore;

            $facility->fill([
                'name' => $attributes['name'],
                'sharing' => $attributes['sharing'],
                'capacity' => $attributes['capacity'],
                'capacity_unit' => $attributes['capacity_unit'],
                'rate_basis' => $attributes['rate_basis'],
                'active' => $attributes['active'],
            ]);
            $changes = array_keys($facility->getDirty());

            ($this->updateChargeItem)($actor, $chargeItem, [
                'name' => $attributes['name'],
                'code' => $chargeItem->transactionCode->code,
                'category' => TransactionCategory::Facility,
                'description' => $chargeItem->description,
                'default_amount' => $attributes['default_amount'],
                'charging_unit' => ChargingUnit::PerItem,
                'allow_override' => true,
                'active' => $attributes['active'],
                'auto_add' => false,
                'tax_ids' => $taxIds,
            ]);

            if ($chargeItem->refresh()->default_amount !== $amountBefore) {
                $changes[] = 'default_amount';
            }

            if ($taxIds !== $taxIdsBefore) {
                $changes[] = 'tax_ids';
            }

            $facility->save();

            if ($facility->sharing === FacilitySharing::Private) {
                $facility->sessions()->delete();
            }

            if ($changes !== []) {
                activity()
                    ->performedOn($facility)
                    ->causedBy($actor)
                    ->event('facility.updated')
                    ->withProperties(['property_id' => $facility->property_id, 'changed' => $changes])
                    ->log('Facility updated');
            }

            return $facility;
        });
    }
}
