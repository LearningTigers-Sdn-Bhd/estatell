<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosDiningOptionInputData;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a dining option of an outlet. An option that charges service charge needs the outlet's service
 * charge transaction code, so folio postings and the daily journal book it to the same GL code
 * (`service_charge_code_required`). Names are unique per outlet (`name_taken`), the rate is 0 to 100 and the packaging fee
 * is not negative (`rate_invalid`). Making an option the default clears the old default. A refusal writes nothing.
 * See docs/34-native-pos/02-menu-catalog.md#7-dining-options--defaults.
 */
class SavePosDiningOption
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, PosDiningOptionInputData $data, ?PosDiningOption $option = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $data, $option): PosActionResult {
            if ($outlet->property_id !== $property->id || ($option !== null && $option->pos_outlet_id !== $outlet->id)) {
                return PosActionResult::refused('outlet_invalid');
            }

            if (BigDecimal::of($data->serviceChargeRate)->isLessThan(0) || BigDecimal::of($data->serviceChargeRate)->isGreaterThan(100) || BigDecimal::of($data->packagingFee)->isNegative()) {
                return PosActionResult::refused('rate_invalid');
            }

            if ($data->applyServiceCharge && $outlet->service_charge_transaction_code_id === null) {
                return PosActionResult::refused('service_charge_code_required');
            }

            $name = trim($data->name);

            $taken = PosDiningOption::query()
                ->where('pos_outlet_id', $outlet->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($option !== null, fn ($query) => $query->whereKeyNot($option->id))
                ->exists();

            if ($taken) {
                return PosActionResult::refused('name_taken');
            }

            if ($data->isDefault) {
                PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->when($option !== null, fn ($query) => $query->whereKeyNot($option->id))->update(['is_default' => false]);
            }

            $created = $option === null;
            $option ??= new PosDiningOption;
            $option->fill([
                'name' => $name,
                'kind' => $data->kind,
                'apply_service_charge' => $data->applyServiceCharge,
                'service_charge_rate' => $data->serviceChargeRate,
                'service_charge_taxable' => $data->serviceChargeTaxable,
                'packaging_fee' => $data->packagingFee,
                'is_default' => $data->isDefault,
                'active' => $data->active,
            ]);
            $option->property_id = $property->id;
            $option->pos_outlet_id = $outlet->id;
            $option->save();

            activity()->performedOn($option)->causedBy($actor)->event($created ? 'pos.dining_option_created' : 'pos.dining_option_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'name' => $name])
                ->log($created ? 'POS dining option created' : 'POS dining option updated');

            return PosActionResult::saved($option);
        });
    }
}
