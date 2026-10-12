<?php

use App\Actions\Catalogues\ReorderChargeItems;
use App\Actions\Catalogues\SeedChargeItems;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\ChargingUnit;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chargeItemPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Breakfast buffet',
        'code' => 'BREAKFAST',
        'category' => TransactionCategory::FoodAndBeverage->value,
        'description' => 'Daily buffet breakfast',
        'default_amount' => '25.00',
        'charging_unit' => ChargingUnit::PerGuest->value,
        'allow_override' => true,
        'active' => true,
        'tax_ids' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chargeItemPayloadFor(ChargeItem $chargeItem, array $overrides = []): array
{
    $chargeItem->loadMissing('transactionCode.applicableTaxes');

    return array_merge([
        'name' => $chargeItem->name,
        'code' => $chargeItem->transactionCode->code,
        'category' => $chargeItem->transactionCode->category->value,
        'description' => $chargeItem->description,
        'default_amount' => $chargeItem->default_amount,
        'charging_unit' => $chargeItem->charging_unit->value,
        'allow_override' => $chargeItem->allow_override,
        'active' => $chargeItem->active,
        'tax_ids' => $chargeItem->transactionCode->applicableTaxes->pluck('id')->all(),
    ], $overrides);
}

test('the list loads the bounded catalogue in active order with its form data', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    $serviceTax = Tax::factory()->for($property)->create(['name' => 'Service tax', 'enabled' => true]);
    Tax::factory()->for($property)->create(['name' => 'Draft tax', 'enabled' => false]);
    $second = ChargeItem::factory()->for($property)->create(['name' => 'Second', 'position' => 2]);
    ChargeItem::factory()->for($property)->create(['name' => 'First', 'position' => 1, 'charging_unit' => ChargingUnit::PerNight, 'default_amount' => null]);
    ChargeItem::factory()->for($property)->inactive()->create(['name' => 'Inactive', 'position' => 1]);
    ChargeItem::factory()->create(['name' => 'Another property']);
    $serviceTax->applicableTransactionCodes()->attach($second->transaction_code_id);

    $this->actingAs($owner)
        ->get(route('settings.charge-items.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/charge-items/index')
            ->has('chargeItems', 3)
            ->where('chargeItems', fn ($items) => collect($items)->pluck('name')->all() === ['First', 'Second', 'Inactive'])
            ->where('chargeItems.0.chargingUnit', 'per_night')
            ->where('chargeItems.0.defaultAmount', null)
            ->where('chargeItems.1.code', $second->transactionCode->code)
            ->where('chargeItems.1.taxIds', [$serviceTax->id])
            ->where('chargeItems.2.active', false)
            ->where('taxes', fn ($taxes) => collect($taxes)->pluck('name')->all() === ['Service tax', 'Draft tax'])
            ->where('categories', TransactionCategory::chargeItemOptions())
            ->where('chargingUnits', ChargingUnit::options())
            ->where('currency', Currency::Myr->value));
});

test('owners create charge items at the end with defaults and their own charge code, then land on the edit page', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $tax = Tax::factory()->for($property)->create();
    ChargeItem::factory()->for($property)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.charge-items.store', $property->code), [
            'name' => ' Breakfast buffet ',
            'code' => ' breakfast buffet ',
            'category' => TransactionCategory::FoodAndBeverage->value,
            'default_amount' => '25.00',
            'tax_ids' => [$tax->id],
        ])
        ->assertSessionHasNoErrors();

    $chargeItem = ChargeItem::query()->where('name', 'Breakfast buffet')->sole();
    $transactionCode = $chargeItem->transactionCode;

    expect($chargeItem->property_id)->toBe($property->id)
        ->and($chargeItem->position)->toBe(5)
        ->and($chargeItem->default_amount)->toBeNull()
        ->and($chargeItem->allow_override)->toBeTrue()
        ->and($chargeItem->charging_unit)->toBe(ChargingUnit::PerItem)
        ->and($chargeItem->active)->toBeTrue()
        ->and($transactionCode->property_id)->toBe($property->id)
        ->and($transactionCode->code)->toBe('BREAKFAST_BUFFET')
        ->and($transactionCode->name)->toBe('Breakfast buffet')
        ->and($transactionCode->kind)->toBe(TransactionKind::Charge)
        ->and($transactionCode->category)->toBe(TransactionCategory::FoodAndBeverage)
        ->and($transactionCode->system_key)->toBeNull()
        ->and($transactionCode->applicableTaxes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'charge_item.created')->count())->toBe(1);
});

test('creating a charge item redirects to its edit page with a toast', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $response = $this->actingAs($owner)->post(route('settings.charge-items.store', $property->code), [
        'name' => 'Breakfast',
        'code' => 'BREAKFAST',
        'category' => TransactionCategory::FoodAndBeverage->value,
    ]);

    $chargeItem = ChargeItem::query()->sole();

    $response->assertRedirect(route('settings.charge-items.edit', [$property->code, $chargeItem]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Breakfast created. Set its price and taxes.']);
});

test('the edit page loads the item with its form data', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    $tax = Tax::factory()->for($property)->create(['name' => 'Service tax']);
    $chargeItem = ChargeItem::factory()->for($property)->create(['name' => 'Breakfast', 'default_amount' => '25.00']);
    $tax->applicableTransactionCodes()->attach($chargeItem->transaction_code_id);

    $this->actingAs($owner)
        ->get(route('settings.charge-items.edit', [$property->code, $chargeItem]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/charge-items/edit')
            ->where('chargeItem.id', $chargeItem->id)
            ->where('chargeItem.name', 'Breakfast')
            ->where('chargeItem.defaultAmount', '25.00')
            ->where('chargeItem.taxIds', [$tax->id])
            ->where('taxes', fn ($taxes) => collect($taxes)->pluck('name')->all() === ['Service tax'])
            ->where('categories', TransactionCategory::chargeItemOptions())
            ->where('chargingUnits', ChargingUnit::options())
            ->where('currency', Currency::Myr->value));
});

test('items priced when charging always allow the amount to be entered', function () {
    $owner = User::factory()->owner()->create();
    $chargeItem = ChargeItem::factory()->for($owner->property)->create(['default_amount' => '25.00', 'allow_override' => false]);

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$owner->property->code, $chargeItem]), chargeItemPayloadFor($chargeItem, [
            'default_amount' => '',
            'allow_override' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect($chargeItem->refresh()->default_amount)->toBeNull()
        ->and($chargeItem->allow_override)->toBeTrue();
});

test('Malaysia properties receive starter charge items that own the general charge codes', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedTransactionCodes::class)($property, 'MY');

    app(SeedChargeItems::class)($property, 'MY');
    $laundry = $property->chargeItems()->where('name', 'Laundry')->sole();
    $laundry->update(['name' => 'Laundry service', 'default_amount' => '15.00', 'active' => false]);
    app(SeedChargeItems::class)($property, 'MY');

    $items = $property->chargeItems()->with('transactionCode')->ordered()->get();

    expect($items->map(fn (ChargeItem $item): ?string => $item->transactionCode->system_key)->all())->toBe([
        SystemTransactionCode::FoodAndBeverageRevenue->value,
        SystemTransactionCode::LaundryRevenue->value,
        SystemTransactionCode::MinibarRevenue->value,
        SystemTransactionCode::FeeRevenue->value,
    ])
        ->and($items->whereNotNull('default_amount'))->toHaveCount(1)
        ->and($items->every(fn (ChargeItem $item): bool => $item->allow_override))->toBeTrue()
        ->and($laundry->refresh()->name)->toBe('Laundry service')
        ->and($laundry->active)->toBeFalse();

    expect(fn () => app(SeedChargeItems::class)($property, 'SG'))->toThrow(InvalidArgumentException::class);
});

test('starter items keep their system code and category but follow the item name and status', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::FeeRevenue)->create();
    $chargeItem = ChargeItem::factory()->for($property)->for($transactionCode)->create(['name' => 'General fee']);

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $chargeItem]), chargeItemPayloadFor($chargeItem, [
            'name' => 'Service fee',
            'code' => 'SERVICE',
            'category' => TransactionCategory::Other->value,
            'active' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect($transactionCode->refresh()->code)->toBe('FEE')
        ->and($transactionCode->category)->toBe(TransactionCategory::Fee)
        ->and($transactionCode->name)->toBe('Service fee')
        ->and($transactionCode->active)->toBeFalse();
});

test('new charge item fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    ChargeItem::factory()->for($owner->property)->inactive()->create(['name' => 'Breakfast buffet']);
    TransactionCode::factory()->for($owner->property)->create(['code' => 'ROOM']);

    $this->actingAs($owner)
        ->post(route('settings.charge-items.store', $owner->property->code), array_merge([
            'name' => 'Laundry bag',
            'code' => 'LAUNDRY_BAG',
            'category' => TransactionCategory::Laundry->value,
        ], $overrides))
        ->assertSessionHasErrors($field);

    expect(ChargeItem::query()->count())->toBe(1);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name stays unique after deactivation' => [['name' => 'breakfast BUFFET'], 'name'],
    'code is required' => [['code' => ' '], 'code'],
    'code is at most 20 characters' => [['code' => str_repeat('A', 21)], 'code'],
    'code is unique among transaction codes' => [['code' => 'room'], 'code'],
    'category is a charge item category' => [['category' => TransactionCategory::Room->value], 'category'],
]);

test('charge item edit fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $chargeItem = ChargeItem::factory()->for($property)->create(['name' => 'Laundry bag']);
    ChargeItem::factory()->for($property)->inactive()->create(['name' => 'Breakfast buffet']);
    TransactionCode::factory()->for($property)->create(['code' => 'ROOM']);
    $foreignTax = Tax::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $chargeItem]), chargeItemPayloadFor($chargeItem, $overrides === ['tax_ids' => 'foreign'] ? ['tax_ids' => [$foreignTax->id]] : $overrides))
        ->assertSessionHasErrors($field);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name stays unique after deactivation' => [['name' => 'breakfast BUFFET'], 'name'],
    'amount is positive' => [['default_amount' => '0'], 'default_amount'],
    'amount has at most two decimal places' => [['default_amount' => '1.001'], 'default_amount'],
    'charging unit is known' => [['charging_unit' => 'per_room'], 'charging_unit'],
    'code is required' => [['code' => ' '], 'code'],
    'code is unique among transaction codes' => [['code' => 'room'], 'code'],
    'category is a charge item category' => [['category' => TransactionCategory::Room->value], 'category'],
    'taxes come from the same property' => [['tax_ids' => 'foreign'], 'tax_ids'],
]);

test('the item form may remove any tax, even the last charge of an enabled tax', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $chargeItem = ChargeItem::factory()->for($property)->create();
    $serviceTax = Tax::factory()->for($property)->create(['enabled' => true]);
    $serviceTax->applicableTransactionCodes()->attach($chargeItem->transaction_code_id);

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $chargeItem]), chargeItemPayloadFor($chargeItem, ['tax_ids' => []]))
        ->assertSessionHasNoErrors();

    expect($serviceTax->applicableTransactionCodes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'charge_item.updated')->sole()->properties['changed'])->toBe(['tax_ids']);
});

test('owners update deactivate and reactivate charge items and their codes without logging no-op saves', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    ChargeItem::factory()->for($property)->create(['position' => 4]);
    $chargeItem = ChargeItem::factory()->for($property)->create([
        'name' => 'Breakfast buffet',
        'description' => 'Daily buffet breakfast',
        'default_amount' => '25.00',
        'charging_unit' => ChargingUnit::PerGuest,
        'allow_override' => true,
        'position' => 2,
    ]);
    $url = route('settings.charge-items.update', [$property->code, $chargeItem]);

    $this->actingAs($owner)->put($url, chargeItemPayloadFor($chargeItem, [
        'name' => 'Buffet',
        'code' => 'BUFFET',
        'category' => TransactionCategory::FoodAndBeverage->value,
        'active' => false,
    ]));

    $transactionCode = $chargeItem->transactionCode()->sole();

    expect($chargeItem->refresh()->active)->toBeFalse()
        ->and($transactionCode->code)->toBe('BUFFET')
        ->and($transactionCode->name)->toBe('Buffet')
        ->and($transactionCode->category)->toBe(TransactionCategory::FoodAndBeverage)
        ->and($transactionCode->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(1);

    $this->put($url, chargeItemPayloadFor($chargeItem, ['active' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Buffet saved.']);

    expect($chargeItem->refresh()->active)->toBeTrue()
        ->and($chargeItem->position)->toBe(5)
        ->and($transactionCode->refresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(2);

    $this->put($url, chargeItemPayloadFor($chargeItem));

    expect(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(2);
});

test('reordering saves active positions and rejects stale duplicate and foreign lists', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$first, $second, $third] = ChargeItem::factory()->count(3)->for($property)
        ->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $inactive = ChargeItem::factory()->for($property)->inactive()->create(['position' => 4]);
    $foreign = ChargeItem::factory()->create();
    $url = route('settings.charge-items.reorder', $property->code);

    $this->actingAs($owner)->put($url, ['order' => [$third->id, $first->id, $second->id]]);

    expect($property->chargeItems()->active()->ordered()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and($inactive->refresh()->position)->toBe(4);

    $this->put($url, ['order' => [$first->id, $second->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The charge items changed. Reload the page and try again.']);
    $this->put($url, ['order' => [$first->id, $first->id, $second->id]])
        ->assertSessionHasErrors('order.1');

    expect(app(ReorderChargeItems::class)($property, $owner, [$first->id, $second->id, $foreign->id]))->toBeFalse()
        ->and(Activity::query()->where('event', 'charge_item.reordered')->count())->toBe(1);
});

test('the database enforces charge item invariants', function (array $attributes) {
    $property = Property::factory()->create();
    $transactionCode = TransactionCode::factory()->for($property)->create();

    DB::table('charge_items')->insert(array_merge([
        'property_id' => $property->id,
        'transaction_code_id' => $transactionCode->id,
        'name' => 'Breakfast',
        'description' => null,
        'default_amount' => 25,
        'charging_unit' => ChargingUnit::PerItem->value,
        'allow_override' => true,
        'position' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
})->throws(QueryException::class)->with([
    'positive amount' => [['default_amount' => 0]],
    'open amount allows override' => [['default_amount' => null, 'allow_override' => false]],
    'known charging unit' => [['charging_unit' => 'per_room']],
    'trimmed name' => [['name' => ' Breakfast ']],
]);

test('the database keeps names unique within a property ignoring case', function () {
    $property = Property::factory()->create();
    ChargeItem::factory()->for($property)->create(['name' => 'Breakfast']);

    ChargeItem::factory()->for($property)->inactive()->create(['name' => 'BREAKFAST']);
})->throws(QueryException::class);

test('the database gives each charge item its own transaction code', function () {
    $chargeItem = ChargeItem::factory()->create();

    ChargeItem::factory()->for($chargeItem->property)->create(['transaction_code_id' => $chargeItem->transaction_code_id]);
})->throws(QueryException::class);

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();
    $otherItem = ChargeItem::factory()->for($otherProperty)->create();

    $this->actingAs($frontDesk)->get(route('settings.charge-items.index', $frontDesk->property->code))->assertForbidden();
    $this->post(route('settings.charge-items.store', $frontDesk->property->code), chargeItemPayload())->assertForbidden();
    $this->get(route('settings.charge-items.edit', [$frontDesk->property->code, ChargeItem::factory()->for($frontDesk->property)->create()]))->assertForbidden();

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$owner->property->code, $otherItem]), chargeItemPayload())
        ->assertNotFound();
    $this->get(route('settings.charge-items.index', $otherProperty->code))->assertNotFound();
    $this->get(route('settings.charge-items.edit', [$owner->property->code, $otherItem]))->assertNotFound();

    expect($otherItem->refresh()->name)->not->toBe('Breakfast buffet');
});

test('the settings sidebar lists charge items only with settings permission', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->get(route('settings.charge-items.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.3.groups.3.label', 'Billing setup')
            ->where('navigation.contexts.3.groups.3.items.1.title', 'Charge items')
            ->where('navigation.contexts.3.groups.3.items.1.active', true));

    $this->actingAs($frontDesk)
        ->get(route('dashboard', $frontDesk->property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('navigation.contexts.3.groups.3.items.0'));
});
