<?php

namespace App\Actions\Pos;

use App\Enums\PosStationMode;
use App\Models\PosKitchenStation;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a kitchen station of an outlet, or change one. Names are unique per outlet ignoring case (`station_name_taken`).
 * The alert time must come after the warn time (`station_thresholds_invalid`). A station of another outlet or property
 * is refused (`station_invalid`). A refusal writes nothing. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class SavePosKitchenStation
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?PosKitchenStation $station, string $name, PosStationMode $mode, bool $useInternalName, int $warnAfter, int $alertAfter, ?string $filterDiningKinds = null, int $sortOrder = 0, bool $active = true): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $station, $name, $mode, $useInternalName, $warnAfter, $alertAfter, $filterDiningKinds, $sortOrder, $active): PosActionResult {
            $name = trim($name);

            if ($outlet->property_id !== $property->id || ($station !== null && ($station->property_id !== $property->id || $station->pos_outlet_id !== $outlet->id))) {
                return PosActionResult::refused('station_invalid');
            }

            if ($name === '' || mb_strlen($name) > 80) {
                return PosActionResult::refused('station_name_invalid');
            }

            if ($warnAfter < 1 || $alertAfter <= $warnAfter) {
                return PosActionResult::refused('station_thresholds_invalid');
            }

            $taken = PosKitchenStation::query()
                ->where('pos_outlet_id', $outlet->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($station !== null, fn ($query) => $query->whereKeyNot($station->id))
                ->exists();

            if ($taken) {
                return PosActionResult::refused('station_name_taken');
            }

            $created = $station === null;
            $station ??= new PosKitchenStation;
            $station->fill([
                'name' => $name,
                'mode' => $mode,
                'use_internal_name' => $useInternalName,
                'warn_after_minutes' => $warnAfter,
                'alert_after_minutes' => $alertAfter,
                'filter_dining_kinds' => $filterDiningKinds === null || trim($filterDiningKinds) === '' ? null : trim($filterDiningKinds),
                'sort_order' => max(0, $sortOrder),
                'active' => $active,
            ]);
            $station->property_id = $property->id;
            $station->pos_outlet_id = $outlet->id;
            $station->save();

            activity()->performedOn($station)->causedBy($actor)->event($created ? 'pos.kitchen_station_created' : 'pos.kitchen_station_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'name' => $name])
                ->log($created ? 'POS kitchen station created' : 'POS kitchen station updated');

            return PosActionResult::saved($station);
        });
    }
}
