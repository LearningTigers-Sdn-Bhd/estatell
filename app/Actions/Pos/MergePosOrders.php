<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fold one open check into another of the same outlet: every live line moves to the target, the source is marked Merged and
 * priced to nothing, and the target is priced again with the higher pre-bill count. A source with a bill discount is refused
 * (`source_has_discounts`) because that discount cannot be pro-rated onto another check, and a source holding a meal
 * entitlement line is refused (`line_not_movable`). A refusal writes nothing.
 */
class MergePosOrders
{
    public function __construct(private MovePosOrderItems $movePosOrderItems) {}

    public function __invoke(User $actor, Property $property, PosOrder $source, PosOrder $target): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $source, $target): PosActionResult {
                $source = PosOrder::query()->where('property_id', $property->id)->whereKey($source->id)->lockForUpdate()->first();

                if ($source === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($source->discounts()->exists()) {
                    return PosActionResult::refused('source_has_discounts');
                }

                $lineIds = array_values($source->lines()
                    ->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)
                    ->where('line_kind', '!=', PosOrderLineKind::PackagingFee->value)
                    ->whereNull('parent_combo_line_id')
                    ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

                if ($lineIds === []) {
                    return PosActionResult::refused('no_lines');
                }

                $moved = ($this->movePosOrderItems)($actor, $property, $source, $target, $lineIds);

                if ($moved->isRefused()) {
                    throw new PosRefusedException((string) $moved->refusal);
                }

                $source->refresh()->forceFill(['status' => PosOrderStatus::Merged])->save();

                activity()->performedOn($source)->causedBy($actor)->event('pos.order_merged')
                    ->withProperties(['property_id' => $property->id, 'into_pos_order_id' => $target->id])
                    ->log('POS orders merged');

                return $moved;
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}
