<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosItem;
use App\Models\PosOrder;
use App\Models\PosOutletItem;
use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\OpenPosBusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Void a whole open order that was never paid. Every line is voided with it and the order is stamped with the Open business
 * date, so the day's void report always lists it on the right date. An order with a fired line or a printed pre-bill needs a
 * manager (`approval_required`), and the reason is mandatory (`void_reason_invalid`). Fired lines return their stock when asked.
 * A settled order is refunded, not voided (`order_not_open`). A refusal writes nothing.
 */
class VoidPosOrder
{
    public function __construct(private OpenPosBusinessDate $openPosBusinessDate, private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, ?PosVoidReason $reason, ?string $reasonText = null, ?bool $returnStock = null, ?User $approver = null): PosActionResult
    {
        $registerId = null;
        $saved = DB::transaction(function () use ($actor, $property, $order, $reason, $reasonText, $returnStock, $approver, &$registerId): PosActionResult {
            $businessDate = $this->openPosBusinessDate->lock($property);

            if (is_string($businessDate)) {
                return PosActionResult::refused($businessDate);
            }

            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $lines = $order->lines()->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)->lockForUpdate()->get();

            // A check with no lines left is only discarded, so it needs no reason.
            $reason = $reason === null ? null : PosVoidReason::query()->where('property_id', $property->id)->where('active', true)->find($reason->id);

            if ($reason === null && $lines->isNotEmpty()) {
                return PosActionResult::refused('void_reason_invalid');
            }

            $fired = $lines->contains(fn ($line): bool => $line->kitchen_status->isLocked() && $line->pos_item_variant_id !== null);

            if (($fired || $order->prebill_print_count >= 1) && $approver === null) {
                return PosActionResult::refused('approval_required');
            }

            $return = $returnStock ?? ($reason !== null && $reason->default_return_stock);
            $text = $reasonText === null || trim($reasonText) === '' ? null : trim($reasonText);

            foreach ($lines as $line) {
                $wasFired = $line->kitchen_status->isLocked() && $line->pos_item_variant_id !== null;
                $line->forceFill([
                    'kitchen_status' => PosKitchenStatus::Voided,
                    'void_reason_id' => $reason?->id,
                    'void_reason_text' => $text,
                    'voided_by_user_id' => $actor->id,
                    'void_approved_by_user_id' => $approver?->id,
                    'return_stock_on_void' => $wasFired && $return,
                ])->save();

                if ($wasFired && $return) {
                    $quantity = (int) $line->quantity;
                    PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $line->pos_item_variant_id)->whereNotNull('stock_countdown')->increment('stock_countdown', $quantity);
                    PosItem::query()->whereKey($line->variant->pos_item_id)->whereNotNull('shared_stock_count')->increment('shared_stock_count', $quantity);
                }
            }

            $order->forceFill([
                'status' => PosOrderStatus::Voided,
                'business_date' => $businessDate->date,
                'voided_at' => now(),
                'void_reason_id' => $reason?->id,
                'void_reason_text' => $text,
                'voided_by_user_id' => $actor->id,
                'void_approved_by_user_id' => $approver?->id,
                'closed_by_user_id' => $actor->id,
            ])->save();

            activity()->performedOn($order)->causedBy($actor)->event('pos.order_voided')
                ->withProperties(['property_id' => $property->id, 'reason' => $reason?->label, 'lines' => $lines->count(), 'approved_by_user_id' => $approver?->id, 'business_date' => $businessDate->date->toDateString()])
                ->log('POS order voided');

            $registerId = $order->shift?->pos_register_id;

            return PosActionResult::saved($order->refresh());
        });

        if (! $saved->isRefused() && $registerId !== null) {
            ($this->bumpCfdVersion)($registerId);
        }

        return $saved;
    }
}
