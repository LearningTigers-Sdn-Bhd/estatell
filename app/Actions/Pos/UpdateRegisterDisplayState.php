<?php

namespace App\Actions\Pos;

use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Point a register's customer-facing display at the order the cashier is working on, or clear it. The order must belong to
 * the register's outlet, so a tablet never shows another outlet's check. Bumps the display version after commit so a
 * polling screen never reads pre-commit state. A refusal writes nothing.
 * See docs/34-native-pos/10-customer-facing-display.md.
 */
class UpdateRegisterDisplayState
{
    public function __construct(private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(Property $property, PosRegister $register, ?int $orderId, bool $checkoutActive): PosActionResult
    {
        $result = DB::transaction(function () use ($property, $register, $orderId, $checkoutActive): PosActionResult {
            $register = PosRegister::query()->where('property_id', $property->id)->whereKey($register->id)->lockForUpdate()->first();

            if ($register === null) {
                return PosActionResult::refused('register_invalid');
            }

            if ($orderId !== null) {
                $order = PosOrder::query()
                    ->where('property_id', $property->id)
                    ->where('pos_outlet_id', $register->pos_outlet_id)
                    ->find($orderId);

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }
            }

            $register->forceFill([
                'active_pos_order_id' => $orderId,
                'active_order_updated_at' => now(),
                'cfd_checkout_active' => $orderId === null ? false : $checkoutActive,
            ])->save();

            return PosActionResult::saved($register);
        });

        if (! $result->isRefused()) {
            ($this->bumpCfdVersion)($register->id);
        }

        return $result;
    }
}
