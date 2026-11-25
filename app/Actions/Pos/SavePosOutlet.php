<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosOutletData;
use App\Features\NativePos;
use App\Models\ChargeItem;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosOutletTaxCheck;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Create or update an outlet. The code is uppercased and unique per property. The charge item must be an active item
 * of the property, and every enabled tax on its transaction code must be inclusive: the POS sends the guest-signed
 * total with SST and service charge already in it, so an exclusive tax would tax the receipt twice
 * (`exclusive_tax_not_allowed`). An outlet being deactivated skips the charge item checks. A native POS outlet (needs the
 * property's Native POS feature) may have no charge item unless an external mapping is live, and gets its default dining
 * options on first save. A refusal writes nothing.
 * See docs/33-pos/01-outlets-and-postings.md.
 */
class SavePosOutlet
{
    public function __construct(private PosOutletTaxCheck $taxCheck, private SeedPosDiningOptions $seedPosDiningOptions) {}

    public function __invoke(User $actor, Property $property, PosOutletData $data, ?PosOutlet $outlet = null): PosOutletResult
    {
        return DB::transaction(function () use ($actor, $property, $data, $outlet): PosOutletResult {
            if ($outlet !== null && $outlet->property_id !== $property->id) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_CHARGE_ITEM_INVALID);
            }

            $code = mb_strtoupper(trim($data->code));

            if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $code) !== 1) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_CODE_INVALID);
            }

            $taken = PosOutlet::query()
                ->where('property_id', $property->id)
                ->where('code', $code)
                ->when($outlet !== null, fn ($query) => $query->whereKeyNot($outlet->id))
                ->exists();

            if ($taken) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_CODE_TAKEN);
            }

            if ($data->nativePosEnabled && ! Feature::for($property)->active(NativePos::class)) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_NATIVE_POS_DISABLED);
            }

            $item = $data->defaultChargeItemId === null
                ? null
                : ChargeItem::query()->where('property_id', $property->id)->find($data->defaultChargeItemId);

            if ($data->defaultChargeItemId === null) {
                if (! $data->nativePosEnabled) {
                    return PosOutletResult::refused(PosOutletResult::REFUSAL_CHARGE_ITEM_INVALID);
                }
            } elseif ($item === null || ($data->active && ! $item->active)) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_CHARGE_ITEM_INVALID);
            } elseif ($data->active && $this->taxCheck->hasExclusiveTax($property, $item->transaction_code_id)) {
                return PosOutletResult::refused(PosOutletResult::REFUSAL_EXCLUSIVE_TAX_NOT_ALLOWED);
            }

            $created = $outlet === null;
            $outlet ??= new PosOutlet;
            $outlet->fill([
                'name' => trim($data->name),
                'code' => $code,
                'default_charge_item_id' => $item?->id,
                'active' => $data->active,
                'native_pos_enabled' => $data->nativePosEnabled,
            ]);
            $outlet->property_id = $property->id;
            $outlet->save();

            if ($outlet->native_pos_enabled) {
                ($this->seedPosDiningOptions)($outlet);
            }

            activity()
                ->performedOn($outlet)
                ->causedBy($actor)
                ->event($created ? 'pos.outlet_created' : 'pos.outlet_updated')
                ->withProperties(['property_id' => $property->id, 'code' => $code, 'active' => $outlet->active])
                ->log($created ? 'POS outlet created' : 'POS outlet updated');

            return PosOutletResult::saved($outlet);
        });
    }
}
