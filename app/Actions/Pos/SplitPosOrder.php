<?php

namespace App\Actions\Pos;

use App\Models\PosOrder;
use App\Models\PosTable;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Split chosen lines off an open order onto a new open check (an "AA" split), on the same table unless another is named.
 * The new check points at the original through `parent_order_id`, opens on the same shift and dining option, and takes
 * the original's pre-bill count. The client sends a `client_uuid`, so a tap sent twice returns the same check. Refusals are
 * those of `CreatePosOrder` and `MovePosOrderItems`, and nothing is written when one happens.
 */
class SplitPosOrder
{
    public function __construct(private CreatePosOrder $createPosOrder, private MovePosOrderItems $movePosOrderItems) {}

    /**
     * @param  list<int>  $lineIds
     */
    public function __invoke(User $actor, Property $property, PosOrder $order, array $lineIds, string $clientUuid, ?PosTable $table = null): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $lineIds, $clientUuid, $table): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null || $order->pos_shift_id === null) {
                    return PosActionResult::refused('order_invalid');
                }

                $created = ($this->createPosOrder)($actor, $property, $order->shift, $order->diningOption, $clientUuid, 1, $order->reservationUnit, $table ?? $order->table);

                if ($created->isRefused() || $created->replayed) {
                    return $created;
                }

                /** @var PosOrder $child */
                $child = $created->model;
                $child->forceFill(['parent_order_id' => $order->id, 'source' => $order->source])->save();

                $moved = ($this->movePosOrderItems)($actor, $property, $order, $child, $lineIds);

                if ($moved->isRefused()) {
                    throw new PosRefusedException((string) $moved->refusal);
                }

                return PosActionResult::saved($child->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}
