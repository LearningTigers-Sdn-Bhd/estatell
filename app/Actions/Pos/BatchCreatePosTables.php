<?php

namespace App\Actions\Pos;

use App\Models\PosFloor;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a run of tables on a floor in one go: prefix `A`, 1 to 12, 4 seats makes A01 to A12, laid out in a grid so the
 * editor starts tidy. At most 100 at a time (`range_invalid`). If any code already exists on the floor, nothing is created
 * (`table_code_exists`). Returns the first table. A refusal writes nothing.
 */
class BatchCreatePosTables
{
    private const PER_ROW = 6;

    private const CELL = 120;

    public function __invoke(User $actor, Property $property, PosFloor $floor, string $prefix, int $start, int $end, int $seats = 4): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $floor, $prefix, $start, $end, $seats): PosActionResult {
            $prefix = strtoupper(trim($prefix));

            if ($floor->property_id !== $property->id) {
                return PosActionResult::refused('floor_invalid');
            }

            if ($start < 0 || $end < $start || $end - $start >= 100 || $seats < 1 || $seats > 99 || strlen($prefix) > 12) {
                return PosActionResult::refused('range_invalid');
            }

            $codes = [];

            for ($number = $start; $number <= $end; $number++) {
                $codes[] = $prefix.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            }

            $taken = PosTable::query()->where('pos_floor_id', $floor->id)->whereIn(DB::raw('upper(code)'), $codes)->exists();

            if ($taken) {
                return PosActionResult::refused('table_code_exists');
            }

            $placed = PosTable::query()->where('pos_floor_id', $floor->id)->count();
            $first = null;

            foreach ($codes as $index => $code) {
                $slot = $placed + $index;
                $table = new PosTable(['code' => $code, 'seats' => $seats, 'pos_x' => ($slot % self::PER_ROW) * self::CELL, 'pos_y' => intdiv($slot, self::PER_ROW) * self::CELL]);
                $table->property_id = $property->id;
                $table->pos_floor_id = $floor->id;
                $table->save();
                $first ??= $table;
            }

            activity()->performedOn($floor)->causedBy($actor)->event('pos.tables_created')
                ->withProperties(['property_id' => $property->id, 'codes' => $codes])
                ->log('POS tables created');

            return PosActionResult::saved($first);
        });
    }
}
