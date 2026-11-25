<?php

use App\Actions\Pos\SavePosItem;
use App\Actions\Pos\SavePosModifierGroup;
use App\Actions\Pos\SeedPosDiningOptions;
use App\Actions\Pos\ToggleModifierOptionAvailability;
use App\Actions\Pos\UpdateOutletItemAvailability;
use App\Actions\Pos\UpdateSharedStockCount;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Data\Pos\PosModifierGroupInputData;
use App\Data\Pos\PosModifierOptionInputData;
use App\Enums\PosAvailability;
use App\Enums\PosTenderType;
use App\Models\PaymentMethod;
use App\Models\PosCategory;
use App\Models\PosOutletPaymentMethod;
use App\Services\Pos\OutletMenuSnapshot;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/PosTerminalSetup.php';
require_once __DIR__.'/PosMenuTest.php';

function posSnapshot(array $s): array
{
    return app(OutletMenuSnapshot::class)($s['outlet']);
}

it('carries the categories, items, variants and prices the outlet sells', function () {
    $s = posMenuSetup();
    app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Latte', $s['category']->id, [
        new PosItemVariantInputData('12.00', 'Hot', sku: 'L-H', barcode: '111'),
        new PosItemVariantInputData('13.00', 'Iced', sku: 'L-I'),
    ], priceIncludesTax: true));

    $snapshot = posSnapshot($s);

    expect($snapshot['outlet']['code'])->toBe($s['outlet']->code)
        ->and($snapshot['categories'])->toHaveCount(1)
        ->and($snapshot['categories'][0]['name'])->toBe('Coffee')
        ->and($snapshot['categories'][0]['items'][0]['name'])->toBe('Latte')
        ->and($snapshot['categories'][0]['items'][0]['priceIncludesTax'])->toBeTrue()
        ->and(array_column($snapshot['categories'][0]['items'][0]['variants'], 'name'))->toBe(['Hot', 'Iced'])
        ->and(array_column($snapshot['categories'][0]['items'][0]['variants'], 'price'))->toBe(['12.00', '13.00'])
        ->and($snapshot['categories'][0]['items'][0]['variants'][0]['barcode'])->toBe('111');
});

it('uses the outlet price override and hides what the outlet does not sell', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte', '12.00');
    $mocha = posMenuItem($s, 'Mocha', '14.00');
    app(UpdateOutletItemAvailability::class)($s['actor'], $s['property'], $s['outlet'], $latte->variants->first(), PosAvailability::PosOnly, '15.00');
    app(UpdateOutletItemAvailability::class)($s['actor'], $s['property'], $s['outlet'], $mocha->variants->first(), PosAvailability::None);

    $items = posSnapshot($s)['categories'][0]['items'];

    expect(array_column($items, 'name'))->toBe(['Latte'])
        ->and($items[0]['variants'][0]['price'])->toBe('15.00');
});

it('leaves out inactive categories, items and variants, and a category with nothing to sell', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte');
    posMenuItem($s, 'Old', '5.00', ['active' => false]);
    $emptyCategory = PosCategory::factory()->create(['property_id' => $s['property']->id, 'transaction_code_id' => $s['code']->id, 'name' => 'Empty']);
    PosCategory::factory()->create(['property_id' => $s['property']->id, 'transaction_code_id' => $s['code']->id, 'name' => 'Closed', 'active' => false]);
    app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Latte', $s['category']->id, [
        new PosItemVariantInputData('12.00', 'Hot', $latte->variants->first()->id),
        new PosItemVariantInputData('13.00', 'Iced', active: false),
    ]), $latte);

    $snapshot = posSnapshot($s);

    expect(array_column($snapshot['categories'], 'name'))->toBe(['Coffee'])
        ->and(array_column($snapshot['categories'][0]['items'], 'name'))->toBe(['Latte'])
        ->and(array_column($snapshot['categories'][0]['items'][0]['variants'], 'name'))->toBe(['Hot'])
        ->and($emptyCategory->exists)->toBeTrue();
});

it('shows modifier groups without the choices switched off', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte');
    $group = app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Milk', [
        new PosModifierOptionInputData('Whole'), new PosModifierOptionInputData('Oat', priceDelta: '2.00'),
    ], [$latte->id], minSelect: 1, maxSelect: 1))->model;

    $groups = posSnapshot($s)['categories'][0]['items'][0]['modifierGroups'];
    expect($groups)->toHaveCount(1)->and(array_column($groups[0]['options'], 'name'))->toBe(['Whole', 'Oat'])
        ->and($groups[0]['minSelect'])->toBe(1)->and($groups[0]['maxSelect'])->toBe(1);

    app(ToggleModifierOptionAvailability::class)($s['actor'], $s['property'], $group->options()->where('name', 'Oat')->first(), PosAvailability::None);

    expect(array_column(posSnapshot($s)['categories'][0]['items'][0]['modifierGroups'][0]['options'], 'name'))->toBe(['Whole']);
});

it('flags sold out variants from the outlet countdown or the shared item count', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte');
    $soup = posMenuItem($s, 'Soup');
    $cake = posMenuItem($s, 'Cake');
    app(UpdateStockCountdown::class)($s['actor'], $s['property'], $s['outlet'], $latte->variants->first(), 0);
    app(UpdateSharedStockCount::class)($s['actor'], $s['property'], $soup, 0);
    app(UpdateStockCountdown::class)($s['actor'], $s['property'], $s['outlet'], $cake->variants->first(), 3);

    $items = collect(posSnapshot($s)['categories'][0]['items'])->keyBy('name');

    expect($items['Latte']['variants'][0]['soldOut'])->toBeTrue()
        ->and($items['Soup']['variants'][0]['soldOut'])->toBeTrue()
        ->and($items['Cake']['variants'][0]['soldOut'])->toBeFalse()
        ->and($items['Cake']['variants'][0]['stockCountdown'])->toBe(3);
});

it('carries dining options and the outlet tenders', function () {
    $s = posMenuSetup();
    app(SeedPosDiningOptions::class)($s['outlet']);
    $method = PaymentMethod::factory()->create(['property_id' => $s['property']->id, 'name' => 'Cash']);
    $tender = new PosOutletPaymentMethod(['tender_type' => PosTenderType::Cash, 'pulse_drawer' => true, 'active' => true, 'sort_order' => 0]);
    $tender->property_id = $s['property']->id;
    $tender->pos_outlet_id = $s['outlet']->id;
    $tender->payment_method_id = $method->id;
    $tender->save();

    $snapshot = posSnapshot($s);

    expect(array_column($snapshot['diningOptions'], 'name'))->toBe(['Dine-In', 'Poolside', 'Room Service', 'Takeaway'])
        ->and($snapshot['diningOptions'][0]['isDefault'])->toBeTrue()
        ->and($snapshot['paymentMethods'])->toBe([['id' => $tender->id, 'paymentMethodId' => $method->id, 'name' => 'Cash', 'tenderType' => 'cash', 'pulseDrawer' => true]]);
});

it('changes its version when the catalog changes and not otherwise', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte');
    $first = posSnapshot($s)['version'];

    expect(posSnapshot($s)['version'])->toBe($first);

    app(UpdateOutletItemAvailability::class)($s['actor'], $s['property'], $s['outlet'], $latte->variants->first(), PosAvailability::All, '99.00');
    expect(posSnapshot($s)['version'])->not->toBe($first);
});

it('does not show another property catalog', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    posMenuItem($other, 'Secret');

    expect(posSnapshot($s)['categories'])->toBe([]);
});

it('builds with a fixed number of queries however many items there are', function () {
    $s = posMenuSetup();
    $first = posMenuItem($s, 'Item 0');
    app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Group 0', [new PosModifierOptionInputData('A')], [$first->id]));
    DB::enableQueryLog();
    posSnapshot($s);
    $small = count(DB::getQueryLog());

    foreach (range(1, 15) as $i) {
        $item = posMenuItem($s, "Item $i");
        app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData("Group $i", [new PosModifierOptionInputData('A'), new PosModifierOptionInputData('B')], [$item->id]));
    }

    DB::flushQueryLog();
    posSnapshot($s);

    expect(count(DB::getQueryLog()))->toBe($small);
});
