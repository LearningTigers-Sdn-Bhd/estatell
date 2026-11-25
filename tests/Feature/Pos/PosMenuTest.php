<?php

use App\Actions\Pos\SavePosCategory;
use App\Actions\Pos\SavePosDiningOption;
use App\Actions\Pos\SavePosItem;
use App\Actions\Pos\SavePosModifierGroup;
use App\Actions\Pos\ToggleModifierOptionAvailability;
use App\Actions\Pos\UpdateOutletItemAvailability;
use App\Actions\Pos\UpdateSharedStockCount;
use App\Actions\Pos\UpdateStockCountdown;
use App\Data\Pos\PosCategoryInputData;
use App\Data\Pos\PosDiningOptionInputData;
use App\Data\Pos\PosItemInputData;
use App\Data\Pos\PosItemVariantInputData;
use App\Data\Pos\PosModifierGroupInputData;
use App\Data\Pos\PosModifierOptionInputData;
use App\Enums\PosAvailability;
use App\Enums\PosDiningKind;
use App\Enums\PosPricingType;
use App\Models\MealPeriod;
use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosModifierGroup;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosTerminalSetup.php';

/** @return array{property: Property, actor: User, outlet: PosOutlet, category: PosCategory, code: TransactionCode} */
function posMenuSetup(): array
{
    $setup = nativePosSetup();
    $code = TransactionCode::factory()->create(['property_id' => $setup['property']->id]);
    $category = app(SavePosCategory::class)($setup['manager'], $setup['property'], new PosCategoryInputData('Coffee', $code->id))->model;

    return ['property' => $setup['property'], 'actor' => $setup['manager'], 'outlet' => $setup['outlet'], 'category' => $category, 'code' => $code];
}

function posMenuItem(array $s, string $name = 'Latte', string $price = '12.00', array $overrides = []): PosItem
{
    $data = new PosItemInputData(...array_merge(['name' => $name, 'posCategoryId' => $s['category']->id, 'variants' => [new PosItemVariantInputData($price)]], $overrides));

    return app(SavePosItem::class)($s['actor'], $s['property'], $data)->model;
}

it('saves a category and refuses a duplicate name, a foreign transaction code and a foreign category', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    $save = app(SavePosCategory::class);

    expect($s['category']->name)->toBe('Coffee')->and($s['category']->transaction_code_id)->toBe($s['code']->id)
        ->and($save($s['actor'], $s['property'], new PosCategoryInputData(' coffee ', $s['code']->id))->refusal)->toBe('name_taken')
        ->and($save($s['actor'], $s['property'], new PosCategoryInputData('Tea', $other['code']->id))->refusal)->toBe('transaction_code_invalid')
        ->and($save($s['actor'], $s['property'], new PosCategoryInputData('Tea', $s['code']->id), $other['category'])->refusal)->toBe('category_invalid')
        ->and(PosCategory::query()->where('property_id', $s['property']->id)->count())->toBe(1);

    $renamed = $save($s['actor'], $s['property'], new PosCategoryInputData('Coffee', $s['code']->id, 'KOPI', 'category-3', 2, false), $s['category']);
    expect($renamed->model->internal_name)->toBe('KOPI')->and($renamed->model->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'pos.category_created')->where('subject_id', $s['category']->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'pos.category_updated')->where('subject_id', $s['category']->id)->count())->toBe(1);
});

it('creates an item with its variant and keeps at least one active variant', function () {
    $s = posMenuSetup();
    $item = posMenuItem($s, 'Latte', '12.50');

    expect($item->variants)->toHaveCount(1)
        ->and($item->variants->first()->name)->toBe('Regular')
        ->and($item->variants->first()->price)->toBe('12.50')
        ->and($item->variants->first()->pricing_type)->toBe(PosPricingType::Fixed);

    $none = app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Mocha', $s['category']->id, []), null);
    $inactive = app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Mocha', $s['category']->id, [new PosItemVariantInputData('10.00', active: false)]));

    expect($none->refusal)->toBe('variant_required')->and($inactive->refusal)->toBe('variant_required')
        ->and(PosItem::query()->where('name', 'Mocha')->exists())->toBeFalse();
});

it('updates, adds and deactivates variants without deleting any', function () {
    $s = posMenuSetup();
    $item = posMenuItem($s, 'Latte', '12.00');
    $regular = $item->variants->first();

    $result = app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Latte', $s['category']->id, [
        new PosItemVariantInputData('13.00', 'Hot', $regular->id, sku: 'LAT-H'),
        new PosItemVariantInputData('14.00', 'Iced', sku: 'LAT-I', cost: '4.00'),
    ]), $item);

    expect($result->isRefused())->toBeFalse();
    $variants = $item->fresh()->variants()->orderBy('id')->get();
    expect($variants->pluck('name')->all())->toBe(['Hot', 'Iced'])->and($variants->pluck('price')->all())->toBe(['13.00', '14.00'])
        ->and($variants->first()->id)->toBe($regular->id);

    app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Latte', $s['category']->id, [new PosItemVariantInputData('14.00', 'Iced', $variants->last()->id)]), $item);
    expect(PosItemVariant::query()->where('pos_item_id', $item->id)->count())->toBe(2)
        ->and($item->variants()->where('active', true)->pluck('name')->all())->toBe(['Iced']);
});

it('keeps SKUs and barcodes unique per property, and not across properties', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    posMenuItem($s, 'Latte', '12.00', ['variants' => [new PosItemVariantInputData('12.00', sku: 'SKU-1', barcode: '999')]]);
    $save = fn (array $setup, PosItemVariantInputData $variant, string $name = 'Mocha') => app(SavePosItem::class)($setup['actor'], $setup['property'], new PosItemInputData($name, $setup['category']->id, [$variant]));

    expect($save($s, new PosItemVariantInputData('9.00', sku: 'SKU-1'))->refusal)->toBe('sku_taken')
        ->and($save($s, new PosItemVariantInputData('9.00', barcode: '999'))->refusal)->toBe('barcode_taken')
        ->and($save($other, new PosItemVariantInputData('9.00', sku: 'SKU-1', barcode: '999'))->isRefused())->toBeFalse()
        ->and(app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('Dup', $s['category']->id, [
            new PosItemVariantInputData('1.00', sku: 'X'), new PosItemVariantInputData('2.00', 'Large', sku: 'X'),
        ]))->refusal)->toBe('sku_taken');
});

it('refuses an item that points at another property or a negative price', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    $period = MealPeriod::factory()->create(['property_id' => $other['property']->id]);
    $save = fn (array $o) => app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData(...array_merge(['name' => 'X', 'posCategoryId' => $s['category']->id, 'variants' => [new PosItemVariantInputData('5.00')]], $o)));

    expect($save(['posCategoryId' => $other['category']->id])->refusal)->toBe('category_invalid')
        ->and($save(['transactionCodeId' => $other['code']->id])->refusal)->toBe('transaction_code_invalid')
        ->and($save(['mealPeriodId' => $period->id])->refusal)->toBe('meal_period_invalid')
        ->and($save(['variants' => [new PosItemVariantInputData('-1.00')]])->refusal)->toBe('price_invalid')
        ->and($save(['variants' => [new PosItemVariantInputData('5.00', id: 999999)]])->refusal)->toBe('variant_invalid')
        ->and(app(SavePosItem::class)($s['actor'], $s['property'], new PosItemInputData('X', $s['category']->id, [new PosItemVariantInputData('5.00')]), $other['category']->items()->first() ?? posMenuItem($other))->refusal)->toBe('item_invalid');
});

it('links an item to a meal period and a variable price', function () {
    $s = posMenuSetup();
    $period = MealPeriod::factory()->create(['property_id' => $s['property']->id]);

    $item = posMenuItem($s, 'Set Breakfast', '0.00', ['mealPeriodId' => $period->id, 'priceIncludesTax' => true, 'serviceChargeExempt' => true, 'variants' => [new PosItemVariantInputData('0.00', pricingType: PosPricingType::Variable)]]);

    expect($item->meal_period_id)->toBe($period->id)->and($item->price_includes_tax)->toBeTrue()->and($item->service_charge_exempt)->toBeTrue()
        ->and($item->variants->first()->pricing_type)->toBe(PosPricingType::Variable);
});

it('saves a modifier group with choices and the items that offer it', function () {
    $s = posMenuSetup();
    $latte = posMenuItem($s, 'Latte');
    $mocha = posMenuItem($s, 'Mocha');
    $other = posMenuSetup();

    $result = app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData(
        'Milk', [new PosModifierOptionInputData('Whole'), new PosModifierOptionInputData('Oat', priceDelta: '2.00')],
        [$latte->id, $mocha->id], minSelect: 1, maxSelect: 1, freeIncludedCount: 1,
    ));

    $group = $result->model;
    expect($result->isRefused())->toBeFalse()
        ->and($group->options()->pluck('name')->all())->toBe(['Whole', 'Oat'])
        ->and($group->items()->pluck('pos_items.id')->all())->toBe([$latte->id, $mocha->id])
        ->and($latte->modifierGroups()->pluck('pos_modifier_groups.name')->all())->toBe(['Milk']);

    expect(app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Bad', [], [], minSelect: 2, maxSelect: 1))->refusal)->toBe('select_range_invalid')
        ->and(app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Bad', [], [posMenuItem($other)->id]))->refusal)->toBe('item_invalid')
        ->and(app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Bad', [new PosModifierOptionInputData('X', 999999)]), $group)->refusal)->toBe('option_invalid')
        ->and(app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Milk', []), $other['category'] ? PosModifierGroup::factory()->create(['property_id' => $other['property']->id]) : null)->refusal)->toBe('group_invalid');
});

it('deactivates choices left out of a group save and keeps them', function () {
    $s = posMenuSetup();
    $group = app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Size', [new PosModifierOptionInputData('Small'), new PosModifierOptionInputData('Large')]))->model;
    $small = $group->options()->where('name', 'Small')->first();

    app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Size', [new PosModifierOptionInputData('Small', $small->id)]), $group);

    expect($group->options()->count())->toBe(2)->and($group->options()->where('active', true)->pluck('name')->all())->toBe(['Small']);
});

it('switches a modifier choice off everywhere with the modifier 86', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    $group = app(SavePosModifierGroup::class)($s['actor'], $s['property'], new PosModifierGroupInputData('Milk', [new PosModifierOptionInputData('Oat')]))->model;
    $option = $group->options()->first();

    expect(app(ToggleModifierOptionAvailability::class)($s['actor'], $s['property'], $option, PosAvailability::None)->model->availability)->toBe(PosAvailability::None)
        ->and(app(ToggleModifierOptionAvailability::class)($other['actor'], $other['property'], $option, PosAvailability::All)->refusal)->toBe('option_invalid')
        ->and($option->fresh()->availability)->toBe(PosAvailability::None);
});

it('saves dining options, one default, and needs a service charge code for a charging option', function () {
    $s = posMenuSetup();
    $outlet = $s['outlet'];
    $save = app(SavePosDiningOption::class);

    $withoutCode = $save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Dine-In', PosDiningKind::DineIn, isDefault: true));
    expect($withoutCode->refusal)->toBe('service_charge_code_required');

    $outlet->forceFill(['service_charge_transaction_code_id' => TransactionCode::factory()->create(['property_id' => $s['property']->id])->id])->save();

    $first = $save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Dine-In', PosDiningKind::DineIn, isDefault: true))->model;
    $second = $save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Takeaway', PosDiningKind::Takeaway, false, '0.00', isDefault: true, packagingFee: '0.50'))->model;

    expect($first->fresh()->is_default)->toBeFalse()->and($second->is_default)->toBeTrue()->and($second->packaging_fee)->toBe('0.50')
        ->and($save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('takeaway', PosDiningKind::Takeaway, false))->refusal)->toBe('name_taken')
        ->and($save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Bad', PosDiningKind::Delivery, false, '101.00'))->refusal)->toBe('rate_invalid')
        ->and($save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Bad', PosDiningKind::Delivery, false, packagingFee: '-1.00'))->refusal)->toBe('rate_invalid');

    $other = posMenuSetup();
    expect($save($s['actor'], $s['property'], $other['outlet'], new PosDiningOptionInputData('Pool', PosDiningKind::Poolside, false))->refusal)->toBe('outlet_invalid')
        ->and($save($s['actor'], $s['property'], $outlet, new PosDiningOptionInputData('Pool', PosDiningKind::Poolside, false), $other['outlet']->diningOptions()->first() ?? $first)->isRefused())->toBeFalse();
});

it('sets an outlet price override and availability, and refuses another property', function () {
    $s = posMenuSetup();
    $other = posMenuSetup();
    $variant = posMenuItem($s)->variants->first();
    $update = app(UpdateOutletItemAvailability::class);

    $row = $update($s['actor'], $s['property'], $s['outlet'], $variant, PosAvailability::PosOnly, '15.00')->model;
    expect($row->price_override)->toBe('15.00')->and($row->availability)->toBe(PosAvailability::PosOnly);

    $again = $update($s['actor'], $s['property'], $s['outlet'], $variant, PosAvailability::All, null)->model;
    expect($again->id)->toBe($row->id)->and($again->price_override)->toBeNull()
        ->and(PosOutletItem::query()->count())->toBe(1)
        ->and($update($s['actor'], $s['property'], $other['outlet'], $variant, PosAvailability::All)->refusal)->toBe('outlet_invalid')
        ->and($update($s['actor'], $s['property'], $s['outlet'], $variant, PosAvailability::All, '-1.00')->refusal)->toBe('price_invalid');
});

it('sets and clears a stock countdown with a daily reset, and a shared item count', function () {
    $s = posMenuSetup();
    $item = posMenuItem($s);
    $variant = $item->variants->first();
    $count = app(UpdateStockCountdown::class);

    $row = $count($s['actor'], $s['property'], $s['outlet'], $variant, 20, 20)->model->fresh();
    expect($row->stock_countdown)->toBe(20)->and($row->daily_stock_reset)->toBe(20)->and($row->availability)->toBe(PosAvailability::All);

    expect($count($s['actor'], $s['property'], $s['outlet'], $variant, -1)->model->stock_countdown)->toBe(-1)
        ->and($count($s['actor'], $s['property'], $s['outlet'], $variant, null)->model->stock_countdown)->toBeNull()
        ->and(PosOutletItem::query()->count())->toBe(1);

    expect(app(UpdateSharedStockCount::class)($s['actor'], $s['property'], $item, 12, 12)->model->shared_stock_count)->toBe(12)
        ->and(app(UpdateSharedStockCount::class)($s['actor'], $s['property'], $item, null)->model->shared_stock_count)->toBeNull()
        ->and(app(UpdateSharedStockCount::class)(posMenuSetup()['actor'], posMenuSetup()['property'], $item, 5)->refusal)->toBe('item_invalid');
});
