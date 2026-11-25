<?php

namespace App\Actions\Pos;

use App\Models\PosFloor;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a floor of an outlet's floor plan, or rename, reorder or switch off an existing one. Names are unique per outlet
 * ignoring case (`floor_name_taken`). A floor from another outlet or property is refused (`floor_invalid`). A refusal
 * writes nothing.
 */
class SavePosFloor
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, ?PosFloor $floor, string $name, int $sortOrder = 0, bool $active = true): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $floor, $name, $sortOrder, $active): PosActionResult {
            $name = trim($name);

            if ($outlet->property_id !== $property->id || $name === '' || mb_strlen($name) > 80) {
                return PosActionResult::refused('floor_invalid');
            }

            if ($floor !== null && ($floor->property_id !== $property->id || $floor->pos_outlet_id !== $outlet->id)) {
                return PosActionResult::refused('floor_invalid');
            }

            $taken = PosFloor::query()->where('pos_outlet_id', $outlet->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($floor !== null, fn ($query) => $query->whereKeyNot($floor->id))->exists();

            if ($taken) {
                return PosActionResult::refused('floor_name_taken');
            }

            $floor ??= new PosFloor;
            $floor->fill(['name' => $name, 'sort_order' => max(0, $sortOrder), 'active' => $active]);
            $floor->property_id = $property->id;
            $floor->pos_outlet_id = $outlet->id;
            $floor->save();

            activity()->performedOn($floor)->causedBy($actor)->event('pos.floor_saved')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id])
                ->log('POS floor saved');

            return PosActionResult::saved($floor);
        });
    }
}
