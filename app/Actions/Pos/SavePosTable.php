<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosTableInputData;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edit one table: its code, label, seats, shape, switch, and its place and size on the floor plan (what the drag-and-drop
 * editor saves). Codes are unique per floor ignoring case (`table_code_taken`). Size and place stay inside a 4000 unit
 * canvas (`layout_invalid`). A table from another property is refused (`table_invalid`). A refusal writes nothing.
 */
class SavePosTable
{
    public function __invoke(User $actor, Property $property, PosTable $table, PosTableInputData $data): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $table, $data): PosActionResult {
            $table = PosTable::query()->where('property_id', $property->id)->whereKey($table->id)->lockForUpdate()->first();

            if ($table === null) {
                return PosActionResult::refused('table_invalid');
            }

            $code = strtoupper(trim($data->code));

            if ($code === '' || strlen($code) > 20 || $data->seats < 1 || $data->seats > 99) {
                return PosActionResult::refused('table_invalid');
            }

            if ($data->width < 40 || $data->height < 40 || $data->width > 800 || $data->height > 800 || $data->x < 0 || $data->y < 0 || $data->x > 4000 || $data->y > 4000) {
                return PosActionResult::refused('layout_invalid');
            }

            $taken = PosTable::query()->where('pos_floor_id', $table->pos_floor_id)->whereRaw('upper(code) = ?', [$code])->whereKeyNot($table->id)->exists();

            if ($taken) {
                return PosActionResult::refused('table_code_taken');
            }

            $table->fill(['code' => $code, 'label' => $data->label === null || trim($data->label) === '' ? null : trim($data->label), 'seats' => $data->seats, 'shape' => $data->shape, 'pos_x' => $data->x, 'pos_y' => $data->y, 'width' => $data->width, 'height' => $data->height, 'active' => $data->active])->save();

            activity()->performedOn($table)->causedBy($actor)->event('pos.table_saved')
                ->withProperties(['property_id' => $property->id])
                ->log('POS table saved');

            return PosActionResult::saved($table);
        });
    }
}
