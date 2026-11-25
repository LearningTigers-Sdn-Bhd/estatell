<?php

namespace App\Actions\Pos;

use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosOutlet;
use App\Models\PosOutletPaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add or update one payment method offered on a POS outlet. Maps a property PaymentMethod to a POS tender type
 * (`cash`, `card`, `e_wallet`, `duitnow_qr`, `payment_voucher`) for settlement and daily journal clearing.
 */
class SavePosOutletPaymentMethod
{
    public function __invoke(
        User $actor,
        Property $property,
        PosOutlet $outlet,
        int $paymentMethodId,
        PosTenderType $tenderType,
        bool $pulseDrawer = false,
        bool $active = true,
        int $sortOrder = 0,
        ?PosOutletPaymentMethod $existing = null,
    ): PosActionResult {
        return DB::transaction(function () use ($actor, $property, $outlet, $paymentMethodId, $tenderType, $pulseDrawer, $active, $sortOrder, $existing): PosActionResult {
            if ($outlet->property_id !== $property->id || ! $outlet->pos_enabled) {
                return PosActionResult::refused('outlet_invalid');
            }

            if ($existing !== null && $existing->pos_outlet_id !== $outlet->id) {
                return PosActionResult::refused('payment_method_invalid');
            }

            if (! $tenderType->isOutletTender()) {
                return PosActionResult::refused('payment_method_invalid');
            }

            $method = PaymentMethod::query()->where('property_id', $property->id)->find($paymentMethodId);

            if ($method === null || ($active && ! $method->active)) {
                return PosActionResult::refused('payment_method_invalid');
            }

            $duplicate = PosOutletPaymentMethod::query()
                ->where('pos_outlet_id', $outlet->id)
                ->where('payment_method_id', $method->id)
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($duplicate) {
                return PosActionResult::refused('payment_method_duplicate');
            }

            $created = $existing === null;
            $row = $existing ?? new PosOutletPaymentMethod;
            $row->property_id = $property->id;
            $row->pos_outlet_id = $outlet->id;
            $row->fill([
                'payment_method_id' => $method->id,
                'tender_type' => $tenderType,
                'pulse_drawer' => $pulseDrawer,
                'active' => $active,
                'sort_order' => $sortOrder,
            ]);
            $row->save();

            activity()->performedOn($row)->causedBy($actor)->event($created ? 'pos.payment_method_created' : 'pos.payment_method_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'payment_method_id' => $method->id, 'tender_type' => $tenderType->value, 'active' => $active])
                ->log($created ? 'POS outlet payment method added' : 'POS outlet payment method updated');

            return PosActionResult::saved($row);
        });
    }
}
