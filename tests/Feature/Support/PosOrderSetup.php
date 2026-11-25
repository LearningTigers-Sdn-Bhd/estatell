<?php

use App\Actions\Pos\AddOrderLine;
use App\Actions\Pos\CreatePosOrder;
use App\Actions\Pos\OpenPosShift;
use App\Actions\Pos\SavePosCategory;
use App\Actions\Pos\SavePosItem;
use App\Actions\Pos\SeedPosDiningOptions;
use App\Data\Pos\PosCategoryInputData;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Data\Pos\PosOrderLineInputData;
use App\Data\Pos\PosTenderInputData;
use App\Enums\PaymentMethodType;
use App\Enums\PosTenderType;
use App\Features\Pos;
use App\Models\PaymentMethod;
use App\Models\PosDiningOption;
use App\Models\PosItemVariant;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutlet;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosRegister;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

require_once __DIR__.'/PosSetup.php';

/**
 * A live property with Native POS on and a ready outlet: SST 6% on the food code (registered), 10% service charge, a
 * Dine-In default and a Takeaway option, cash and card tenders, one register with an Open shift (RM 200 float), and a
 * menu of Steak RM 100.00, Pasta RM 37.93, Iced Mocha RM 14.00 and an included Set Breakfast RM 25.00 (the breakfast
 * meal period). It also has a CheckedIn unit, and with `$meal` that unit is entitled to 3 breakfasts on 14 October.
 *
 * @return array<string, mixed>
 */
function posOrderSetup(bool $meal = false): array
{
    $s = $meal ? mealSetup() : posSetup();
    $property = $s['property'];
    Feature::for($property)->activate(Pos::class);

    $manager = User::factory()->for($property)->withPermissions(['pos.sell', 'pos.shift', 'pos.void', 'pos.refund', 'pos.discount', 'pos.manage', 'pos.menu', 'pos.reports', 'pos.view', 'meals.redeem'])->create();
    $code = fn () => TransactionCode::factory()->create(['property_id' => $property->id]);
    $food = $code();
    $sst = Tax::factory()->for($property)->create(['code' => 'SST', 'rate' => '6.00', 'registration_number' => 'W10-123']);
    $sst->applicableTransactionCodes()->attach($food);

    $outlet = PosOutlet::factory()->create([
        'property_id' => $property->id,
        'pos_enabled' => true,
        'service_charge_transaction_code_id' => $code()->id,
        'packaging_transaction_code_id' => $code()->id,
        'rounding_transaction_code_id' => $code()->id,
        'cash_variance_transaction_code_id' => $code()->id,
        'safe_clearing_transaction_code_id' => $code()->id,
        'require_room_charge_signature' => true,
        'require_meal_redemption_confirmation' => true,
    ]);
    app(SeedPosDiningOptions::class)($outlet);

    $tenders = [];

    foreach ([[PosTenderType::Cash, PaymentMethodType::Cash, true], [PosTenderType::Card, PaymentMethodType::Card, false]] as $index => [$tender, $type, $drawer]) {
        $method = PaymentMethod::factory()->create(['property_id' => $property->id, 'type' => $type, 'requires_reference' => false]);
        $row = new PosOutletPaymentMethod(['tender_type' => $tender, 'pulse_drawer' => $drawer, 'active' => true, 'sort_order' => $index]);
        $row->property_id = $property->id;
        $row->pos_outlet_id = $outlet->id;
        $row->payment_method_id = $method->id;
        $row->save();
        $tenders[$tender->value] = $method;
    }

    $register = PosRegister::factory()->create(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id]);
    $shift = app(OpenPosShift::class)($manager, $property, $register, '200.00')->model;
    $category = app(SavePosCategory::class)($manager, $property, new PosCategoryInputData('Food', $food->id))->model;

    $item = fn (string $name, string $price, array $extra = []) => app(SavePosItem::class)($manager, $property, new PosItemInputData(...array_merge(['name' => $name, 'posCategoryId' => $category->id, 'variants' => [new PosItemVariantInputData($price)]], $extra)))->model->variants->first();

    return [
        ...$s,
        'manager' => $manager,
        'outlet' => $outlet,
        'register' => $register,
        'shift' => $shift,
        'category' => $category,
        'food' => $food,
        'sst' => $sst,
        'dineIn' => PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->where('name', 'Dine-In')->sole(),
        'takeaway' => PosDiningOption::query()->where('pos_outlet_id', $outlet->id)->where('name', 'Takeaway')->sole(),
        'methods' => $tenders,
        'steak' => $item('Steak', '100.00'),
        'pasta' => $item('Pasta', '37.93'),
        'mocha' => $item('Iced Mocha', '14.00'),
        'breakfast' => $item('Set Breakfast', '25.00', ['mealPeriodId' => $meal ? $s['period']->id : null]),
    ];
}

function posNewOrder(array $s, ?PosDiningOption $dining = null, ?ReservationUnit $unit = null): PosOrder
{
    return app(CreatePosOrder::class)($s['manager'], $s['property'], $s['shift'], $dining ?? $s['dineIn'], (string) Str::uuid(), 1, $unit)->model;
}

function posAddLine(array $s, PosOrder $order, PosItemVariant $variant, int $quantity = 1, array $options = []): PosOrderLine
{
    $result = app(AddOrderLine::class)($s['manager'], $s['property'], $order, new PosOrderLineInputData(...array_merge(['variantId' => $variant->id, 'quantity' => $quantity], $options)));

    if ($result->isRefused()) {
        throw new RuntimeException('addLine refused: '.$result->refusal);
    }

    return $result->model;
}

function posCash(string $amount, ?string $tendered = null): PosTenderInputData
{
    return new PosTenderInputData(PosTenderType::Cash, $amount, tenderedAmount: $tendered);
}

function posCard(string $amount): PosTenderInputData
{
    return new PosTenderInputData(PosTenderType::Card, $amount, reference: 'AUTH123');
}
