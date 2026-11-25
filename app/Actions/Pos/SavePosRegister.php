<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\PosRegister;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add or change a register. The name is unique inside the outlet ignoring case (`name_taken`); a register with an open
 * shift cannot be switched off (`shift_already_open`). A refusal writes nothing.
 */
class SavePosRegister
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, string $name, bool $active, ?PosRegister $register = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $name, $active, $register): PosActionResult {
            if ($outlet->property_id !== $property->id || ! $outlet->pos_enabled || ($register !== null && $register->pos_outlet_id !== $outlet->id)) {
                return PosActionResult::refused('outlet_invalid');
            }

            $name = trim($name);

            if ($name === '') {
                return PosActionResult::refused('name_required');
            }

            $taken = PosRegister::query()->where('pos_outlet_id', $outlet->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($register !== null, fn ($query) => $query->whereKeyNot($register->id))->exists();

            if ($taken) {
                return PosActionResult::refused('name_taken');
            }

            if ($register !== null && ! $active && $register->shifts()->where('status', '<>', 'closed')->exists()) {
                return PosActionResult::refused('shift_already_open');
            }

            $register ??= new PosRegister;
            $register->fill(['name' => $name, 'active' => $active]);
            $register->property_id = $property->id;
            $register->pos_outlet_id = $outlet->id;
            $register->save();

            activity()->performedOn($register)->causedBy($actor)->event('pos.register_saved')->withProperties(['property_id' => $property->id, 'name' => $name])->log('POS register saved');

            return PosActionResult::saved($register);
        });
    }
}
