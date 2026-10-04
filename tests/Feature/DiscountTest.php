<?php

use App\Actions\Catalogues\ReorderDiscounts;
use App\Actions\Catalogues\SeedDiscounts;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Discount;
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
function discountPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Staff rate',
        'code' => 'STAFF',
        'type' => DiscountType::Percent->value,
        'value' => '10',
        'allow_override' => false,
        'scope' => DiscountScope::RoomOnly->value,
        'active' => true,
        'transaction_code_ids' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function discountPayloadFor(Discount $discount, array $overrides = []): array
{
    $discount->loadMissing(['transactionCode', 'selectedTransactionCodes']);

    return array_merge([
        'name' => $discount->name,
        'code' => $discount->transactionCode->code,
        'type' => $discount->type->value,
        'value' => $discount->value,
        'allow_override' => $discount->allow_override,
        'scope' => $discount->scope->value,
        'active' => $discount->active,
        'transaction_code_ids' => $discount->selectedTransactionCodes->pluck('id')->all(),
    ], $overrides);
}

test('the list loads the bounded catalogue in active order with charge codes to choose from', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    $laundry = ChargeItem::factory()->for($property)->create(['name' => 'Laundry']);
    TransactionCode::factory()->for($property)->create(['name' => 'Tax code', 'kind' => TransactionKind::Tax]);
    $second = Discount::factory()->for($property)->create(['name' => 'Second', 'position' => 2, 'scope' => DiscountScope::Selected]);
    Discount::factory()->for($property)->open()->create(['name' => 'First', 'position' => 1]);
    Discount::factory()->for($property)->inactive()->create(['name' => 'Inactive', 'position' => 1]);
    Discount::factory()->create(['name' => 'Another property']);
    $second->selectedTransactionCodes()->attach($laundry->transaction_code_id);

    $this->actingAs($owner)
        ->get(route('settings.discounts.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/discounts/index')
            ->where('discounts', fn ($discounts) => collect($discounts)->pluck('name')->all() === ['First', 'Second', 'Inactive'])
            ->where('discounts.0.type', 'open')
            ->where('discounts.0.value', null)
            ->where('discounts.1.code', $second->transactionCode->code)
            ->where('discounts.1.transactionCodeIds', [$laundry->transaction_code_id])
            ->where('discounts.2.active', false)
            ->where('charges', fn ($charges) => collect($charges)->pluck('name')->all() === ['Laundry'])
            ->where('types', DiscountType::options())
            ->where('scopes', DiscountScope::options())
            ->where('currency', Currency::Myr->value));
});

test('owners create discounts at the end with their own discount code', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Discount::factory()->for($property)->create(['position' => 4]);

    $this->actingAs($owner)
        ->post(route('settings.discounts.store', $property->code), discountPayload([
            'name' => ' Staff rate ',
            'code' => ' staff rate ',
            'allow_override' => true,
        ]))
        ->assertRedirect(route('settings.discounts.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Staff rate created.']);

    $discount = Discount::query()->where('name', 'Staff rate')->sole();
    $transactionCode = $discount->transactionCode;

    expect($discount->property_id)->toBe($property->id)
        ->and($discount->position)->toBe(5)
        ->and($discount->value)->toBe('10.00')
        ->and($discount->allow_override)->toBeFalse()
        ->and($transactionCode->code)->toBe('STAFF_RATE')
        ->and($transactionCode->name)->toBe('Staff rate')
        ->and($transactionCode->kind)->toBe(TransactionKind::Adjustment)
        ->and($transactionCode->category)->toBe(TransactionCategory::Discount)
        ->and($transactionCode->system_key)->toBeNull()
        ->and(Activity::query()->where('event', 'discount.created')->count())->toBe(1);
});

test('open discounts have no value and always allow the amount to be entered', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('settings.discounts.store', $owner->property->code), discountPayload([
            'type' => DiscountType::Open->value,
            'value' => '25',
            'allow_override' => false,
        ]))
        ->assertSessionHasNoErrors();

    $discount = Discount::query()->sole();

    expect($discount->value)->toBeNull()
        ->and($discount->allow_override)->toBeTrue();
});

test('selected discounts target charge codes and other scopes clear them', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $laundry = ChargeItem::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('settings.discounts.store', $property->code), discountPayload([
            'type' => DiscountType::Fixed->value,
            'value' => '50',
            'allow_override' => true,
            'scope' => DiscountScope::Selected->value,
            'transaction_code_ids' => [$laundry->transaction_code_id],
        ]))
        ->assertSessionHasNoErrors();

    $discount = Discount::query()->sole();

    expect($discount->selectedTransactionCodes()->pluck('transaction_codes.id')->all())->toBe([$laundry->transaction_code_id])
        ->and($discount->allow_override)->toBeTrue();

    $this->put(route('settings.discounts.update', [$property->code, $discount]), discountPayloadFor($discount, [
        'scope' => DiscountScope::AllCharges->value,
    ]))->assertSessionHasNoErrors();

    expect($discount->selectedTransactionCodes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'discount.updated')->sole()->properties['changed'])->toBe(['scope', 'transaction_code_ids']);
});

test('Malaysia properties receive a starter discount that owns the DISCOUNT code', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    app(SeedTransactionCodes::class)($property, 'MY');

    app(SeedDiscounts::class)($property, 'MY');
    $discount = $property->discounts()->sole();
    $discount->update(['name' => 'Manager discount', 'active' => false]);
    app(SeedDiscounts::class)($property, 'MY');

    expect($property->discounts()->count())->toBe(1)
        ->and($discount->refresh()->name)->toBe('Manager discount')
        ->and($discount->active)->toBeFalse()
        ->and($discount->type)->toBe(DiscountType::Open)
        ->and($discount->scope)->toBe(DiscountScope::AllCharges)
        ->and($discount->transactionCode->system_key)->toBe(SystemTransactionCode::Discount->value);

    expect(fn () => app(SeedDiscounts::class)($property, 'SG'))->toThrow(InvalidArgumentException::class);
});

test('the starter discount keeps its system code but follows the discount name and status', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $transactionCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::Discount)->create();
    $discount = Discount::factory()->for($property)->for($transactionCode)->open()->create(['name' => 'Discount']);

    $this->actingAs($owner)
        ->put(route('settings.discounts.update', [$property->code, $discount]), discountPayloadFor($discount, [
            'name' => 'Manager discount',
            'code' => 'MANAGER',
            'active' => false,
        ]))
        ->assertSessionHasNoErrors();

    expect($transactionCode->refresh()->code)->toBe('DISCOUNT')
        ->and($transactionCode->name)->toBe('Manager discount')
        ->and($transactionCode->active)->toBeFalse();
});

test('discount fields are validated', function (array $overrides, string $field) {
    $owner = User::factory()->owner()->create();
    Discount::factory()->for($owner->property)->inactive()->create(['name' => 'Staff rate']);
    TransactionCode::factory()->for($owner->property)->create(['code' => 'ROOM']);
    $tax = Tax::factory()->for($owner->property)->create();
    $foreign = ChargeItem::factory()->create();
    $codeIds = match ($overrides['transaction_code_ids'] ?? null) {
        'tax' => [$tax->transaction_code_id],
        'foreign' => [$foreign->transaction_code_id],
        default => $overrides['transaction_code_ids'] ?? [],
    };

    $this->actingAs($owner)
        ->post(route('settings.discounts.store', $owner->property->code), discountPayload([...$overrides, 'transaction_code_ids' => $codeIds]))
        ->assertSessionHasErrors($field);

    expect(Discount::query()->count())->toBe(1);
})->with([
    'name is required' => [['name' => ''], 'name'],
    'name stays unique after deactivation' => [['name' => 'staff RATE'], 'name'],
    'code is required' => [['code' => ' '], 'code'],
    'code is at most 20 characters' => [['code' => str_repeat('A', 21)], 'code'],
    'code is unique among transaction codes' => [['code' => 'room'], 'code'],
    'type is known' => [['type' => 'free'], 'type'],
    'percentage is required' => [['value' => ''], 'value'],
    'percentage is at most 100' => [['value' => '100.01'], 'value'],
    'percentage is at least 1' => [['value' => '0.5'], 'value'],
    'fixed amount is positive' => [['type' => DiscountType::Fixed->value, 'value' => '0'], 'value'],
    'fixed amount has at most two decimal places' => [['type' => DiscountType::Fixed->value, 'value' => '1.001'], 'value'],
    'scope is known' => [['scope' => 'everything'], 'scope'],
    'selected needs a charge' => [['scope' => DiscountScope::Selected->value], 'transaction_code_ids'],
    'selected charges are not taxes' => [['scope' => DiscountScope::Selected->value, 'transaction_code_ids' => 'tax'], 'transaction_code_ids'],
    'selected charges come from the same property' => [['scope' => DiscountScope::Selected->value, 'transaction_code_ids' => 'foreign'], 'transaction_code_ids'],
]);

test('owners update deactivate and reactivate discounts and their codes without logging no-op saves', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Discount::factory()->for($property)->create(['position' => 4]);
    $discount = Discount::factory()->for($property)->create(['name' => 'Staff rate', 'position' => 2]);
    $url = route('settings.discounts.update', [$property->code, $discount]);

    $this->actingAs($owner)->put($url, discountPayloadFor($discount, [
        'name' => 'Crew rate',
        'code' => 'CREW',
        'value' => '15',
        'active' => false,
    ]));

    $transactionCode = $discount->transactionCode()->sole();

    expect($discount->refresh()->active)->toBeFalse()
        ->and($discount->value)->toBe('15.00')
        ->and($transactionCode->code)->toBe('CREW')
        ->and($transactionCode->name)->toBe('Crew rate')
        ->and($transactionCode->active)->toBeFalse()
        ->and(Activity::query()->where('event', 'discount.updated')->count())->toBe(1);

    $this->put($url, discountPayloadFor($discount, ['active' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Crew rate saved.']);

    expect($discount->refresh()->active)->toBeTrue()
        ->and($discount->position)->toBe(5)
        ->and($transactionCode->refresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'discount.updated')->count())->toBe(2);

    $this->put($url, discountPayloadFor($discount));

    expect(Activity::query()->where('event', 'discount.updated')->count())->toBe(2);
});

test('reordering saves active positions and rejects stale duplicate and foreign lists', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$first, $second, $third] = Discount::factory()->count(3)->for($property)
        ->sequence(['position' => 1], ['position' => 2], ['position' => 3])->create();
    $inactive = Discount::factory()->for($property)->inactive()->create(['position' => 4]);
    $foreign = Discount::factory()->create();
    $url = route('settings.discounts.reorder', $property->code);

    $this->actingAs($owner)->put($url, ['order' => [$third->id, $first->id, $second->id]]);

    expect($property->discounts()->active()->ordered()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and($inactive->refresh()->position)->toBe(4);

    $this->put($url, ['order' => [$first->id, $second->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The discounts changed. Reload the page and try again.']);
    $this->put($url, ['order' => [$first->id, $first->id, $second->id]])
        ->assertSessionHasErrors('order.1');

    expect(app(ReorderDiscounts::class)($property, $owner, [$first->id, $second->id, $foreign->id]))->toBeFalse()
        ->and(Activity::query()->where('event', 'discount.reordered')->count())->toBe(1);
});

test('the database enforces discount invariants', function (array $attributes) {
    $property = Property::factory()->create();
    $transactionCode = TransactionCode::factory()->for($property)->create();

    DB::table('discounts')->insert(array_merge([
        'property_id' => $property->id,
        'transaction_code_id' => $transactionCode->id,
        'name' => 'Staff rate',
        'type' => DiscountType::Percent->value,
        'value' => 10,
        'allow_override' => false,
        'scope' => DiscountScope::RoomOnly->value,
        'position' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
})->throws(QueryException::class)->with([
    'percentage at most 100' => [['value' => 101]],
    'percentage never overridable' => [['allow_override' => true]],
    'fixed amount positive' => [['type' => DiscountType::Fixed->value, 'value' => 0]],
    'open has no value' => [['type' => DiscountType::Open->value, 'allow_override' => true]],
    'open allows override' => [['type' => DiscountType::Open->value, 'value' => null]],
    'known type' => [['type' => 'free']],
    'known scope' => [['scope' => 'everything']],
    'trimmed name' => [['name' => ' Staff rate ']],
]);

test('the database keeps names unique within a property ignoring case', function () {
    $property = Property::factory()->create();
    Discount::factory()->for($property)->create(['name' => 'Staff rate']);

    Discount::factory()->for($property)->inactive()->create(['name' => 'STAFF RATE']);
})->throws(QueryException::class);

test('routes enforce settings permission and property scoping', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();
    $otherDiscount = Discount::factory()->for($otherProperty)->create();

    $this->actingAs($frontDesk)->get(route('settings.discounts.index', $frontDesk->property->code))->assertForbidden();
    $this->post(route('settings.discounts.store', $frontDesk->property->code), discountPayload())->assertForbidden();

    $this->actingAs($owner)
        ->put(route('settings.discounts.update', [$owner->property->code, $otherDiscount]), discountPayload())
        ->assertNotFound();
    $this->get(route('settings.discounts.index', $otherProperty->code))->assertNotFound();

    expect($otherDiscount->refresh()->name)->not->toBe('Staff rate');
});

test('the settings sidebar lists discounts under billing setup', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->get(route('settings.discounts.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.2.groups.3.label', 'Billing setup')
            ->where('navigation.contexts.2.groups.3.items.4.title', 'Discounts')
            ->where('navigation.contexts.2.groups.3.items.4.active', true));
});
