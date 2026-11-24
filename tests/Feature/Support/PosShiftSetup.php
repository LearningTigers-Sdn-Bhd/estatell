<?php

use App\Actions\Pos\OpenPosShift;
use App\Enums\PaymentMethodType;
use App\Enums\PosOrderStatus;
use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\TransactionCode;

require_once __DIR__.'/NativePosSetup.php';

/** @return array<string, mixed> */
function posShiftSetup(array $outletOverrides = []): array
{
    $setup = nativePosSetup();
    $property = $setup['property'];
    $codes = fn () => TransactionCode::factory()->create(['property_id' => $property->id])->id;
    $setup['outlet']->forceFill(array_merge([
        'native_pos_enabled' => true,
        'cash_variance_transaction_code_id' => $codes(),
        'rounding_transaction_code_id' => $codes(),
        'safe_clearing_transaction_code_id' => $codes(),
    ], $outletOverrides))->save();

    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    $tender = new PosOutletPaymentMethod(['tender_type' => PosTenderType::Cash, 'pulse_drawer' => true, 'active' => true, 'sort_order' => 0]);
    $tender->property_id = $property->id;
    $tender->pos_outlet_id = $setup['outlet']->id;
    $tender->payment_method_id = $cash->id;
    $tender->save();

    $setup['register'] = PosRegister::factory()->create(['property_id' => $property->id, 'pos_outlet_id' => $setup['outlet']->id]);
    $setup['counter'] = TransactionCode::factory()->create(['property_id' => $property->id]);

    return $setup;
}

function posOpenShift(array $s, ?string $cash = '200.00'): PosShift
{
    return app(OpenPosShift::class)($s['manager'], $s['property'], $s['register'], $cash)->model;
}

function posCashPayment(PosShift $shift, string $amount, array $overrides = []): PosOrderPayment
{
    $order = PosOrder::factory()->create(['property_id' => $shift->property_id, 'pos_outlet_id' => $shift->pos_outlet_id, 'pos_shift_id' => $shift->id, 'status' => PosOrderStatus::Settled]);

    return PosOrderPayment::factory()->create(array_merge(['property_id' => $shift->property_id, 'pos_order_id' => $order->id, 'pos_shift_id' => $shift->id, 'tender_type' => PosTenderType::Cash, 'amount' => $amount], $overrides));
}
