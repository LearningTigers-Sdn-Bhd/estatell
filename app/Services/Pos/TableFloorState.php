<?php

namespace App\Services\Pos;

use App\Enums\PosOrderStatus;
use App\Models\PosFloor;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosTable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * What the floor plan shows for an outlet right now: every active floor with its active tables, and for each table whether
 * it is Vacant or Occupied, since when, how many covers, the running total of its open checks, whether a pre-bill was
 * printed, and a multi-bill flag when two or more open checks sit on it. Read only.
 */
class TableFloorState
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function for(PosOutlet $outlet): array
    {
        $floors = PosFloor::query()->where('pos_outlet_id', $outlet->id)->where('active', true)->orderBy('sort_order')->orderBy('id')
            ->with(['tables' => fn ($query) => $query->where('active', true)->orderBy('code')])->get();

        $orders = PosOrder::query()->where('pos_outlet_id', $outlet->id)->where('status', PosOrderStatus::Open->value)->whereNotNull('pos_table_id')
            ->orderBy('opened_at')->get()->groupBy('pos_table_id');

        return $floors->map(fn (PosFloor $floor): array => [
            'id' => $floor->id,
            'name' => $floor->name,
            'tables' => $floor->tables->map(fn (PosTable $table): array => $this->tile($table, $orders->get($table->id, collect())))->values()->all(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, PosOrder>  $open
     * @return array<string, mixed>
     */
    private function tile(PosTable $table, $open): array
    {
        $total = $open->reduce(fn (BigDecimal $sum, PosOrder $order): BigDecimal => $sum->plus($order->grand_total), BigDecimal::zero());

        return [
            'id' => $table->id,
            'code' => $table->code,
            'label' => $table->label,
            'seats' => $table->seats,
            'shape' => $table->shape->value,
            'x' => $table->pos_x,
            'y' => $table->pos_y,
            'width' => $table->width,
            'height' => $table->height,
            'occupied' => $open->isNotEmpty(),
            'openedAt' => $open->first()?->opened_at->toIso8601String(),
            'covers' => (int) $open->sum('guest_count'),
            'total' => (string) $total->toScale(2),
            'preBilled' => $open->contains(fn (PosOrder $order): bool => $order->prebill_print_count >= 1),
            'multiBill' => $open->count() >= 2,
            'orderIds' => $open->pluck('id')->all(),
        ];
    }
}
