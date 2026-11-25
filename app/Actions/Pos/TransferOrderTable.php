<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move an open check onto a different active table of the same outlet (e.g. guests moving from Table A01 to A05).
 */
class TransferOrderTable
{
    public function __invoke(User $actor, Property $property, PosOrder $order, PosTable $table): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $table): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null || $order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $target = PosTable::query()
                ->where('property_id', $property->id)
                ->where('active', true)
                ->with('floor')
                ->find($table->id);

            if ($target === null || ! $target->floor->active || $target->floor->pos_outlet_id !== $order->pos_outlet_id) {
                return PosActionResult::refused('table_invalid');
            }

            $previousTableId = $order->pos_table_id;
            $order->forceFill(['pos_table_id' => $target->id])->save();

            activity()->performedOn($order)->causedBy($actor)->event('pos.order_table_transferred')
                ->withProperties(['property_id' => $property->id, 'from_table_id' => $previousTableId, 'to_table_id' => $target->id])
                ->log('POS order transferred to table '.$target->code);

            return PosActionResult::saved($order);
        });
    }
}
