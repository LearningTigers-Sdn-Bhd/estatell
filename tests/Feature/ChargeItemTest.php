<?php

use App\Actions\Catalogues\ReorderChargeItems;
use App\Enums\ChargingUnit;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
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
function chargeItemPayload(TransactionCode $transactionCode, array $overrides = []): array
{
    return array_merge([
        'name' => 'Breakfast buffet',
        'description' => 'Daily buffet breakfast',
        'transaction_code_id' => $transactionCode->id,
        'default_amount' => '25.00',
        'charging_unit' => ChargingUnit::PerGuest->value,
        'allow_override' => true,
        'active' => true,
    ], $overrides);
}

test('the list loads the bounded catalogue in active order with its picker data', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    $activeCode = TransactionCode::factory()->for($property)->create(['code' => 'FNB', 'name' => 'Food and beverage']);
    $inactiveCode = TransactionCode::factory()->for($property)->inactive()->create(['code' => 'OLD', 'name' => 'Old charge']);
    TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment]);
    ChargeItem::factory()->for($property)->for($activeCode)->create(['name' => 'Second', 'position' => 2]);
    ChargeItem::factory()->for($property)->for($activeCode)->create(['name' => 'First', 'position' => 1, 'charging_unit' => ChargingUnit::PerNight]);
    ChargeItem::factory()->for($property)->for($inactiveCode)->inactive()->create(['name' => 'Inactive', 'position' => 1]);
    ChargeItem::factory()->create(['name' => 'Another property']);

    $this->actingAs($owner)
        ->get(route('settings.charge-items.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/charge-items/index')
            ->has('chargeItems', 3)
            ->where('chargeItems', fn ($items) => collect($items)->pluck('name')->all() === ['First', 'Second', 'Inactive'])
            ->where('chargeItems.0.chargingUnit', 'per_night')
            ->where('chargeItems.2.active', false)
            ->where('transactionCodes', fn ($codes) => collect($codes)->pluck('label')->all() === ['FNB — Food and beverage', 'OLD — Old charge'])
            ->where('chargingUnits', ChargingUnit::options())
            ->where('currency', Currency::Myr->value));
});

test('owners create charge items at the end and may share a transaction code', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->create();
    ChargeItem::factory()->for($property)->for($transactionCode)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.charge-items.store', $property->code), chargeItemPayload($transactionCode, ['name' => ' Breakfast buffet ']))
        ->assertRedirect(route('settings.charge-items.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Breakfast buffet created.']);

    $chargeItem = ChargeItem::query()->where('name', 'Breakfast buffet')->sole();

    expect($chargeItem->property_id)->toBe($property->id)
        ->and($chargeItem->transaction_code_id)->toBe($transactionCode->id)
        ->and($chargeItem->position)->toBe(5)
        ->and($chargeItem->default_amount)->toBe('25.00')
        ->and($chargeItem->charging_unit)->toBe(ChargingUnit::PerGuest)
        ->and($transactionCode->chargeItems()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'charge_item.created')->count())->toBe(1);
});

test('new properties receive no charge items', function () {
    Property::factory()->create();

    expect(ChargeItem::query()->count())->toBe(0);
});

test('charge item fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    $transactionCode = TransactionCode::factory()->for($owner->property)->create();
    ChargeItem::factory()->for($owner->property)->for($transactionCode)->inactive()->create(['name' => 'Breakfast buffet']);

    $this->actingAs($owner)
        ->post(route('settings.charge-items.store', $owner->property->code), chargeItemPayload($transactionCode, $overrides))
        ->assertSessionHasErrors($field);

    expect(ChargeItem::query()->count())->toBe(1);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name stays unique after deactivation' => [['name' => 'breakfast BUFFET'], 'name'],
    'amount is positive' => [['default_amount' => '0'], 'default_amount'],
    'amount has at most two decimal places' => [['default_amount' => '1.001'], 'default_amount'],
    'charging unit is known' => [['charging_unit' => 'per_room'], 'charging_unit'],
]);

test('transaction codes must be active charge codes from the same property', function (string $case) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = match ($case) {
        'inactive' => TransactionCode::factory()->for($property)->inactive()->create(),
        'payment' => TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment]),
        'another property' => TransactionCode::factory()->create(),
        default => throw new LogicException('Unknown transaction-code test case.'),
    };

    $this->actingAs($owner)
        ->post(route('settings.charge-items.store', $property->code), chargeItemPayload($transactionCode))
        ->assertSessionHasErrors('transaction_code_id');

    expect(ChargeItem::query()->count())->toBe(0);
})->with(['inactive', 'payment', 'another property']);

test('an item may keep an inactive transaction code but another item cannot select it', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->create();
    $chargeItem = ChargeItem::factory()->for($property)->for($transactionCode)->create(['name' => 'Laundry']);
    $transactionCode->update(['active' => false]);

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $chargeItem]), chargeItemPayload($transactionCode, ['name' => 'Laundry express']))
        ->assertSessionHasNoErrors();

    $this->post(route('settings.charge-items.store', $property->code), chargeItemPayload($transactionCode, ['name' => 'Another']))
        ->assertSessionHasErrors('transaction_code_id');

    expect($chargeItem->refresh()->name)->toBe('Laundry express');
});

test('owners update deactivate and reactivate charge items without logging no-op saves', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->create();
    ChargeItem::factory()->for($property)->for($transactionCode)->create(['position' => 4]);
    $chargeItem = ChargeItem::factory()->for($property)->for($transactionCode)->create([
        'name' => 'Breakfast buffet',
        'description' => 'Daily buffet breakfast',
        'default_amount' => '25.00',
        'charging_unit' => ChargingUnit::PerGuest,
        'allow_override' => true,
        'position' => 2,
    ]);
    $url = route('settings.charge-items.update', [$property->code, $chargeItem]);

    $this->actingAs($owner)->put($url, chargeItemPayload($transactionCode, ['active' => false]));

    expect($chargeItem->refresh()->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(1);

    $this->put($url, chargeItemPayload($transactionCode, ['active' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Breakfast buffet saved.']);

    expect($chargeItem->refresh()->active)->toBeTrue()
        ->and($chargeItem->position)->toBe(5)
        ->and(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(2);

    $this->put($url, chargeItemPayload($transactionCode));

    expect(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(2);
});

test('reordering saves active positions and rejects stale duplicate and foreign lists', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->create();
    [$first, $second, $third] = ChargeItem::factory()->count(3)->for($property)->for($transactionCode)
        ->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $inactive = ChargeItem::factory()->for($property)->for($transactionCode)->inactive()->create(['position' => 4]);
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
    'known charging unit' => [['charging_unit' => 'per_room']],
    'trimmed name' => [['name' => ' Breakfast ']],
]);

test('the database keeps names unique within a property ignoring case', function () {
    $property = Property::factory()->create();
    $transactionCode = TransactionCode::factory()->for($property)->create();
    ChargeItem::factory()->for($property)->for($transactionCode)->create(['name' => 'Breakfast']);

    ChargeItem::factory()->for($property)->for($transactionCode)->inactive()->create(['name' => 'BREAKFAST']);
})->throws(QueryException::class);

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();
    $otherCode = TransactionCode::factory()->for($otherProperty)->create();
    $otherItem = ChargeItem::factory()->for($otherProperty)->for($otherCode)->create();
    $ownerCode = TransactionCode::factory()->for($owner->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.charge-items.index', $frontDesk->property->code))->assertForbidden();
    $this->post(route('settings.charge-items.store', $frontDesk->property->code), chargeItemPayload($ownerCode))->assertForbidden();

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$owner->property->code, $otherItem]), chargeItemPayload($ownerCode))
        ->assertNotFound();
    $this->get(route('settings.charge-items.index', $otherProperty->code))->assertNotFound();

    expect($otherItem->refresh()->name)->not->toBe('Breakfast buffet');
});

test('the settings sidebar lists charge items only with settings permission', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->get(route('settings.charge-items.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.1.groups.2.label', 'Billing setup')
            ->where('navigation.contexts.1.groups.2.items.1.title', 'Charge items')
            ->where('navigation.contexts.1.groups.2.items.1.active', true));

    $this->actingAs($frontDesk)
        ->get(route('dashboard', $frontDesk->property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('navigation.contexts.1.groups.2.items.0'));
});
