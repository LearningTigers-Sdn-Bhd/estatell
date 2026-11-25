<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosTableInputData;
use App\Models\PosFloor;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a single table on a floor. Codes are unique per floor ignoring case (`table_code_taken`).
 */
class CreatePosTable
{
    public function __invoke(User $actor, Property $property, PosFloor $floor, PosTableInputData $data): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $floor, $data): PosActionResult {
            $floor = PosFloor::query()->where('property_id', $property->id)->whereKey($floor->id)->lockForUpdate()->first();

            if ($floor === null) {
                return PosActionResult::refused('floor_invalid');
            }

            $code = strtoupper(trim($data->code));

            if ($code === '' || strlen($code) > 20 || $data->seats < 1 || $data->seats > 99) {
                return PosActionResult::refused('table_invalid');
            }

            if ($data->width < 40 || $data->height < 40 || $data->width > 800 || $data->height > 800 || $data->x < 0 || $data->y < 0 || $data->x > 4000 || $data->y > 4000) {
                return PosActionResult::refused('layout_invalid');
            }

            if (PosTable::query()->where('pos_floor_id', $floor->id)->whereRaw('upper(code) = ?', [$code])->exists()) {
                return PosActionResult::refused('table_code_taken');
            }

            $table = new PosTable([
                'code' => $code,
                'label' => $data->label === null || trim($data->label) === '' ? null : trim($data->label),
                'seats' => $data->seats,
                'shape' => $data->shape,
                'pos_x' => $data->x,
                'pos_y' => $data->y,
                'width' => $data->width,
                'height' => $data->height,
                'active' => $data->active,
            ]);
            $table->property_id = $property->id;
            $table->pos_floor_id = $floor->id;
            $table->save();

            activity()->performedOn($table)->causedBy($actor)->event('pos.table_created')
                ->withProperties(['property_id' => $property->id, 'pos_floor_id' => $floor->id, 'code' => $code])
                ->log('POS table created');

            return PosActionResult::saved($table);
        });
    }
}
