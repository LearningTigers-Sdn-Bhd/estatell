<?php

use App\Enums\ChargingUnit;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionCategory;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

function boatFareEnable(Property $property): void
{
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    Feature::for($property)->activate(Boats::class);
}

/** A Transport fare, priced per guest, with the given attributes. */
function boatFareMake(Property $property, array $attributes = []): ChargeItem
{
    $fare = ChargeItem::factory()->for($property)->create([
        'name' => 'Speedboat',
        'default_amount' => '35.00',
        'charging_unit' => ChargingUnit::PerGuest,
        ...$attributes,
    ]);
    $fare->transactionCode->update(['category' => TransactionCategory::Transport]);

    return $fare->refresh();
}

/** The Infant band comes first, as age groups are sorted by minimum age. @return array{0: PropertyAgeGroup, 1: PropertyAgeGroup} */
function boatFareAgeGroups(Property $property): array
{
    return [
        PropertyAgeGroup::factory()->for($property)->create(['label' => 'Infant', 'min_age' => 0, 'max_age' => 3]),
        PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 4, 'max_age' => 11]),
    ];
}

/** @param  array<string, mixed>  $overrides */
function boatFarePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Speedboat',
        'code' => 'SPEEDBOAT',
        'default_amount' => '35.00',
        'allow_override' => true,
        'tax_ids' => [],
        'active' => true,
    ], $overrides);
}

/** A departure that uses the fare. Available unless said otherwise. */
function boatFareDepartureUsing(Property $property, ChargeItem $fare, bool $available = true, string $leavesAt = '09:00'): BoatDeparture
{
    return BoatDeparture::factory()->for($property)->create(['charge_item_id' => $fare->id, 'active' => $available, 'leaves_at' => $leavesAt, 'arrives_at' => substr($leavesAt, 0, 2).':45']);
}

test('a new fare is a Transport item priced per guest, never added automatically, with its taxes and child prices', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    [$infant, $child] = boatFareAgeGroups($property);
    $tax = Tax::factory()->for($property)->create(['name' => 'SST']);

    $this->actingAs($owner)
        ->post(route('settings.boats.fares.store', $property->code), boatFarePayload([
            'tax_ids' => [$tax->id],
            'child_prices' => [
                ['property_age_group_id' => $infant->id, 'pricing' => 'free', 'value' => null],
                ['property_age_group_id' => $child->id, 'pricing' => 'percent', 'value' => '50'],
            ],
        ]))
        ->assertRedirect(route('settings.boats.fares.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Speedboat added.']);

    $fare = ChargeItem::query()->where('property_id', $property->id)->sole();

    expect($fare->transactionCode->category)->toBe(TransactionCategory::Transport)
        ->and($fare->transactionCode->code)->toBe('SPEEDBOAT')
        ->and($fare->charging_unit)->toBe(ChargingUnit::PerGuest)
        ->and($fare->default_amount)->toBe('35.00')
        ->and($fare->auto_add)->toBeFalse()
        ->and($fare->active)->toBeTrue()
        ->and($fare->transactionCode->applicableTaxes->pluck('id')->all())->toBe([$tax->id])
        ->and($fare->childPrices()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'charge_item.created')->count())->toBe(1);
});

test('a fare needs an adult price, and its name and code are unique in the property', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    boatFareMake($property, ['name' => 'Ferry']);

    $this->actingAs($owner)
        ->post(route('settings.boats.fares.store', $property->code), boatFarePayload(['default_amount' => '', 'code' => 'FERRY_B']))
        ->assertSessionHasErrors('default_amount');

    $this->actingAs($owner)
        ->post(route('settings.boats.fares.store', $property->code), boatFarePayload(['name' => 'ferry', 'code' => 'FERRY_C']))
        ->assertSessionHasErrors('name');

    expect(ChargeItem::query()->where('property_id', $property->id)->count())->toBe(1);
});

test('editing a fare keeps its code and description, and leaves the child prices alone when none are sent', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $fare = boatFareMake($property, ['description' => 'One way from the jetty']);
    $originalCode = $fare->transactionCode->code;
    [$infant, $child] = boatFareAgeGroups($property);
    $fare->childPrices()->create(['property_age_group_id' => $child->id, 'pricing' => 'percent', 'value' => '50.00']);
    $url = route('settings.boats.fares.update', ['property' => $property->code, 'chargeItem' => $fare->id]);

    $this->actingAs($owner)
        ->put($url, boatFarePayload(['name' => 'Speedboat Express', 'code' => 'NEWCODE', 'default_amount' => '40.00', 'allow_override' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fare saved.']);

    $fare->refresh();

    expect($fare->name)->toBe('Speedboat Express')
        ->and($fare->default_amount)->toBe('40.00')
        ->and($fare->allow_override)->toBeFalse()
        ->and($fare->description)->toBe('One way from the jetty')
        ->and($fare->transactionCode->code)->toBe($originalCode)
        ->and($fare->childPrices()->where('property_age_group_id', $child->id)->value('value'))->toBe('50.00');
});

test('a fare cannot be turned off while an available departure uses it, and nothing is saved or logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $fare = boatFareMake($property);
    boatFareDepartureUsing($property, $fare);

    $this->actingAs($owner)
        ->put(route('settings.boats.fares.update', ['property' => $property->code, 'chargeItem' => $fare->id]), boatFarePayload(['active' => false]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This fare is used by an available departure. Change their fare first.']);

    expect($fare->refresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'charge_item.updated')->count())->toBe(0);
});

test('a fare used only by unavailable departures can be turned off, and turned on again', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $fare = boatFareMake($property);
    boatFareDepartureUsing($property, $fare, available: false);
    $url = route('settings.boats.fares.update', ['property' => $property->code, 'chargeItem' => $fare->id]);

    $this->actingAs($owner)
        ->put($url, boatFarePayload(['active' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Speedboat is unavailable.']);

    expect($fare->refresh()->active)->toBeFalse();

    $this->actingAs($owner)
        ->put($url, boatFarePayload(['active' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Speedboat is available.']);

    expect($fare->refresh()->active)->toBeTrue();
});

test('the fares list shows Transport items only, active first, with available departures counted and the children summary', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    [$infant, $child] = boatFareAgeGroups($property);

    $adultOnly = boatFareMake($property, ['name' => 'Ferry']);
    $adultOnly->childPrices()->createMany([
        ['property_age_group_id' => $infant->id, 'pricing' => 'percent', 'value' => '100.00'],
        ['property_age_group_id' => $child->id, 'pricing' => 'percent', 'value' => '100.00'],
    ]);
    boatFareDepartureUsing($property, $adultOnly);
    boatFareDepartureUsing($property, $adultOnly, available: false, leavesAt: '13:00');

    $speedboat = boatFareMake($property, ['name' => 'Speedboat', 'active' => false]);
    $speedboat->childPrices()->createMany([
        ['property_age_group_id' => $infant->id, 'pricing' => 'free', 'value' => null],
        ['property_age_group_id' => $child->id, 'pricing' => 'percent', 'value' => '50.00'],
    ]);

    boatFareMake($property, ['name' => 'Laundry bag'])->transactionCode->update(['category' => TransactionCategory::Laundry]);

    $this->actingAs($owner)
        ->get(route('settings.boats.fares.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/boats/fares')
            ->has('fares', 2)
            ->where('fares.0.name', 'Ferry')
            ->where('fares.0.usedBy', 1)
            ->where('fares.0.childrenSummary', 'Same as adult')
            ->where('fares.1.name', 'Speedboat')
            ->where('fares.1.usedBy', 0)
            ->where('fares.1.childrenSummary', 'Infant 0–3 Free · Child 4–11 50%'));
});

test('fare routes are 404 for another property, a non-Transport item, and while the feature is off; staff without settings access are refused', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    boatFareEnable($frontDesk->property);
    $other = User::factory()->owner()->create();
    boatFareEnable($other->property);
    $otherFare = boatFareMake($other->property);
    $nonTransport = ChargeItem::factory()->for($property)->create(['default_amount' => '10.00']);
    $offProperty = User::factory()->owner()->create()->property;

    $this->actingAs($frontDesk)
        ->get(route('settings.boats.fares.index', $frontDesk->property->code))
        ->assertForbidden();

    $this->actingAs($owner)
        ->put(route('settings.boats.fares.update', ['property' => $property->code, 'chargeItem' => $otherFare->id]), boatFarePayload())
        ->assertNotFound();

    $this->actingAs($owner)
        ->put(route('settings.boats.fares.update', ['property' => $property->code, 'chargeItem' => $nonTransport->id]), boatFarePayload())
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('settings.boats.fares.index', $offProperty->code))
        ->assertNotFound();

    expect($otherFare->refresh()->name)->toBe('Speedboat')
        ->and($nonTransport->refresh()->default_amount)->toBe('10.00');
});

test('the Boats settings item opens departures, and both tabs are linked', function () {
    $owner = User::factory()->owner()->create();
    boatFareEnable($owner->property);

    $this->actingAs($owner)
        ->get(route('settings.boats', $owner->property->code))
        ->assertRedirect(route('settings.boats.departures.index', $owner->property->code));

    $this->actingAs($owner)
        ->get(route('settings.boats.fares.index', $owner->property->code))
        ->assertOk();
});

test('a fare any departure uses cannot be added to every new booking, and its edit page says so', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $fare = boatFareMake($property);
    $plain = ChargeItem::factory()->for($property)->create(['default_amount' => '20.00']);
    boatFareDepartureUsing($property, $fare, available: false);

    $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $fare]), [
            'name' => $fare->name,
            'code' => $fare->transactionCode->code,
            'category' => TransactionCategory::Transport->value,
            'description' => null,
            'default_amount' => '35.00',
            'charging_unit' => ChargingUnit::PerGuest->value,
            'allow_override' => true,
            'active' => true,
            'tax_ids' => [],
            'auto_add' => true,
        ])
        ->assertSessionHasErrors(['auto_add' => 'Boat trips add this fare. Turn off Add to every new booking first.']);

    expect($fare->refresh()->auto_add)->toBeFalse();

    $this->actingAs($owner)
        ->get(route('settings.charge-items.edit', [$property->code, $fare]))
        ->assertInertia(fn (Assert $page) => $page->where('autoAddLocked', true));

    $this->actingAs($owner)
        ->get(route('settings.charge-items.edit', [$property->code, $plain]))
        ->assertInertia(fn (Assert $page) => $page->where('autoAddLocked', false));
});

test('the charge items page cannot turn off a fare while an available departure uses it', function (bool $available) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatFareEnable($property);
    $fare = boatFareMake($property);
    boatFareDepartureUsing($property, $fare, available: $available);

    $response = $this->actingAs($owner)
        ->put(route('settings.charge-items.update', [$property->code, $fare]), [
            'name' => $fare->name,
            'code' => $fare->transactionCode->code,
            'category' => TransactionCategory::Transport->value,
            'description' => null,
            'default_amount' => '35.00',
            'charging_unit' => ChargingUnit::PerGuest->value,
            'allow_override' => true,
            'active' => false,
            'tax_ids' => [],
        ]);

    if ($available) {
        $response->assertSessionHasErrors(['active' => 'This fare is used by an available departure. Change their fare first.']);
    } else {
        $response->assertSessionHasNoErrors();
    }

    expect($fare->refresh()->active)->toBe($available);
})->with([
    'used by an available departure' => [true],
    'used only by an unavailable departure' => [false],
]);
