<?php

use App\Enums\BillingMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\UnitGuest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A property with a room type of two rooms, one rate plan and 100.00 rates for 2026-10-20 to 2026-10-27, an open
 * reservation with one unit, and a user with the role.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, roomType: RoomType, plan: RatePlan}
 */
function actionsSetup(DefaultRoleEnum $role = DefaultRoleEnum::FrontDesk): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(2)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($night = 0; $night < 7; $night++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => now()->setDate(2026, 10, 20 + $night)->toDateString(), 'amount' => '100.00']);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
    ]);

    return compact('user', 'property', 'reservation', 'unit', 'roomType', 'plan');
}

/**
 * @param  list<string>  $permissions
 */
function actionsRoleWith(User $user, array $permissions): void
{
    $role = Role::query()->create(['property_id' => $user->property_id, 'name' => 'Custom']);
    setPermissionsTeamId($user->property_id);
    $role->givePermissionTo($permissions);
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function actionsUnitPayload(array $setup, array $overrides = []): array
{
    return [
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => '2026-10-23',
        'departure_date' => '2026-10-25',
        'adults' => 2,
        'child_ages' => [],
        ...$overrides,
    ];
}

function actionsRoute(array $setup, string $name, array $extra = []): string
{
    return route($name, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, ...$extra]);
}

test('the action routes need reservations.manage', function (string $method, string $route) {
    $setup = actionsSetup(DefaultRoleEnum::Finance);
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id]);
    $url = actionsRoute($setup, $route, ['unit' => $setup['unit']->id, 'addOn' => $addOn->id]);

    $this->actingAs($setup['user'])->{$method}($url)->assertForbidden();

    expect($setup['reservation']->units()->count())->toBe(1)
        ->and(UnitAddOn::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    ['put', 'reservations.group.update'],
    ['post', 'reservations.units.store'],
    ['post', 'reservations.units.guests.store'],
    ['post', 'reservations.units.add-ons.store'],
    ['delete', 'reservations.units.add-ons.destroy'],
]);

test('another property\'s reservation, unit or add-on returns 404', function () {
    $setup = actionsSetup();
    $foreign = Reservation::factory()->create();
    $foreignUnit = ReservationUnit::factory()->for($foreign)->create();
    $foreignAddOn = UnitAddOn::factory()->create(['reservation_unit_id' => $foreignUnit->id]);
    $ownAddOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id]);
    $this->actingAs($setup['user']);

    $this->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $foreign->id]), actionsUnitPayload($setup))->assertNotFound();
    $this->post(actionsRoute($setup, 'reservations.units.add-ons.store', ['unit' => $foreignUnit->id]), ['charge_item_id' => 1])->assertNotFound();
    $this->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $foreignUnit->id]), ['guest_id' => 1])->assertNotFound();
    $this->delete(actionsRoute($setup, 'reservations.units.add-ons.destroy', ['unit' => $foreignUnit->id, 'addOn' => $foreignAddOn->id]))->assertNotFound();
    $this->delete(actionsRoute($setup, 'reservations.units.add-ons.destroy', ['unit' => $setup['unit']->id, 'addOn' => $foreignAddOn->id]))->assertNotFound();
    $this->get(route('guests.search', ['property' => $foreign->property->code, 'q' => 'ab']))->assertNotFound();

    expect(UnitAddOn::query()->count())->toBe(2)
        ->and($ownAddOn->exists)->toBeTrue();
});

test('a unit is added with a toast and a log', function () {
    $setup = actionsSetup();

    $this->actingAs($setup['user'])
        ->from(actionsRoute($setup, 'reservations.show'))
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup, ['adults' => 2, 'child_ages' => [4, 9]]))
        ->assertRedirect(actionsRoute($setup, 'reservations.show'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unit added.']);

    $unit = $setup['reservation']->units()->latest('id')->firstOrFail();

    expect($setup['reservation']->units()->count())->toBe(2)
        ->and($unit->adults)->toBe(2)
        ->and($unit->childAges()->pluck('age')->all())->toBe([4, 9])
        ->and($unit->nights()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.unit_added')->sole()->causer_id)->toBe($setup['user']->id);
});

test('a price override is saved with its reason', function () {
    $setup = actionsSetup();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup, ['override_total' => '150.00', 'override_reason' => 'Corporate rate']))
        ->assertSessionHasNoErrors();

    $unit = $setup['reservation']->units()->latest('id')->firstOrFail();

    expect($unit->nights()->sum('amount'))->toEqual(150)
        ->and(Activity::query()->where('event', 'reservation.unit_added')->sole()->properties['override_reason'])->toBe('Corporate rate');
});

test('a refused unit shows an inline error and writes nothing', function (array $overrides, string $field, string $message) {
    $setup = actionsSetup();
    Rate::query()->where('date', '2026-10-26')->update(['stop_sell' => true]);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup, $overrides))
        ->assertSessionHasErrors([$field => $message]);

    expect($setup['reservation']->units()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'arrival in the past' => [['arrival_date' => '2026-10-01', 'departure_date' => '2026-10-03'], 'arrival_date', 'Arrival cannot be before today.'],
    'departure before arrival' => [['departure_date' => '2026-10-23'], 'departure_date', 'Departure must be after arrival.'],
    'override without a reason' => [['override_total' => '150.00'], 'override_reason', 'Enter a reason for the price override.'],
    'a restriction without the permission' => [['arrival_date' => '2026-10-25', 'departure_date' => '2026-10-27'], 'form', 'This stay breaks a rate restriction, for example a minimum stay. Ask someone who may book past restrictions.'],
]);

test('a stay with no free room says which night is sold out', function () {
    $setup = actionsSetup();
    $other = Reservation::factory()->create(['property_id' => $setup['property']->id]);

    foreach (range(1, 2) as $ignored) {
        ReservationUnit::factory()->for($other)->withNights()->create([
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => '2026-10-23',
            'departure_date' => '2026-10-25',
        ]);
    }

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup))
        ->assertSessionHasErrors(['room_type_id' => 'No unit is available on 23 Oct 2026.']);

    expect($setup['reservation']->units()->count())->toBe(1);
});

test('a restriction override reason is asked for from staff who may book past restrictions', function () {
    $setup = actionsSetup(DefaultRoleEnum::Owner);
    Rate::query()->where('date', '2026-10-26')->update(['stop_sell' => true]);
    $payload = actionsUnitPayload($setup, ['arrival_date' => '2026-10-25', 'departure_date' => '2026-10-27']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), $payload)
        ->assertSessionHasErrors(['restriction_override_reason' => 'This stay breaks a rate restriction. Enter a reason to book it anyway.']);

    $this->post(actionsRoute($setup, 'reservations.units.store'), [...$payload, 'restriction_override_reason' => 'Owner approved'])
        ->assertSessionHasNoErrors();

    expect($setup['reservation']->units()->count())->toBe(2);
});

test('a staff member with the manage permission only cannot override a restriction', function () {
    $setup = actionsSetup();
    actionsRoleWith($setup['user'], ['reservations.view', 'reservations.manage']);
    Rate::query()->where('date', '2026-10-26')->update(['stop_sell' => true]);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup, ['arrival_date' => '2026-10-25', 'departure_date' => '2026-10-27', 'restriction_override_reason' => 'Please']))
        ->assertSessionHasErrors(['form' => 'You may not book past rate restrictions.']);

    expect($setup['reservation']->units()->count())->toBe(1);
});

test('a closed reservation takes no new unit', function () {
    $setup = actionsSetup();
    $setup['reservation']->forceFill(['status' => 'cancelled'])->save();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup))
        ->assertSessionHasErrors(['form' => 'This reservation is closed, so it cannot change.']);

    expect($setup['reservation']->units()->count())->toBe(1);
});

test('the unit form checks its shape', function (array $overrides, string $field) {
    $setup = actionsSetup();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup, $overrides))
        ->assertSessionHasErrors($field);
})->with([
    'no room type' => [['room_type_id' => null], 'room_type_id'],
    'a bad date' => [['arrival_date' => '23/10/2026'], 'arrival_date'],
    'no adults' => [['adults' => 0], 'adults'],
    'a child age over 17' => [['child_ages' => [18]], 'child_ages.0'],
    'a bad override amount' => [['override_total' => '12,5'], 'override_total'],
]);

test('the group name and billing mode are saved, also on a closed reservation', function () {
    $setup = actionsSetup();
    $setup['reservation']->forceFill(['status' => 'completed'])->save();

    $this->actingAs($setup['user'])
        ->put(actionsRoute($setup, 'reservations.group.update'), ['group_name' => '  Tan family trip ', 'billing_mode' => BillingMode::EachPays->value])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Group details saved.']);

    $setup['reservation']->refresh();

    expect($setup['reservation']->group_name)->toBe('Tan family trip')
        ->and($setup['reservation']->billing_mode)->toBe(BillingMode::EachPays)
        ->and(Activity::query()->where('event', 'reservation.group_updated')->count())->toBe(1);

    $this->put(actionsRoute($setup, 'reservations.group.update'), ['group_name' => '', 'billing_mode' => BillingMode::EachPays->value]);

    expect($setup['reservation']->refresh()->group_name)->toBeNull();
});

test('the group form needs a valid billing mode and a name that fits', function (array $payload, string $field) {
    $setup = actionsSetup();

    $this->actingAs($setup['user'])
        ->put(actionsRoute($setup, 'reservations.group.update'), $payload)
        ->assertSessionHasErrors($field);

    expect(Activity::query()->count())->toBe(0);
})->with([
    'no billing mode' => [['group_name' => 'Trip'], 'billing_mode'],
    'an unknown billing mode' => [['group_name' => 'Trip', 'billing_mode' => 'nobody'], 'billing_mode'],
    'a long name' => [['group_name' => str_repeat('a', 151), 'billing_mode' => 'master_pays'], 'group_name'],
]);

test('an existing guest is attached and the first one becomes primary', function () {
    $setup = actionsSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Faiz']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['guest_id' => $guest->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ahmad Faiz added to the unit.']);

    expect(UnitGuest::query()->sole()->is_primary)->toBeTrue()
        ->and(Activity::query()->where('event', 'reservation.guest_attached')->count())->toBe(1);
});

test('a refused guest shows an inline error and writes nothing', function (string $case, string $field, string $message) {
    $setup = actionsSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $guestId = $guest->id;

    match ($case) {
        'foreign' => $guestId = Guest::factory()->create()->id,
        'merged' => $guest->forceFill(['merged_into_id' => Guest::factory()->create(['property_id' => $setup['property']->id])->id])->save(),
        'attached' => UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'guest_id' => $guest->id]),
        'bed full' => (function () use ($setup): void {
            $setup['roomType']->forceFill(['sell_unit' => 'bed'])->save();
            UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id]);
        })(),
        'closed unit' => $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save(),
    };

    $before = UnitGuest::query()->count();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['guest_id' => $guestId])
        ->assertSessionHasErrors([$field => $message]);

    expect(UnitGuest::query()->count())->toBe($before)
        ->and(Activity::query()->where('event', 'reservation.guest_attached')->count())->toBe(0);
})->with([
    ['foreign', 'guest_id', 'This guest cannot be found.'],
    ['merged', 'guest_id', 'This guest cannot be found.'],
    ['attached', 'guest_id', 'This guest already stays in this unit.'],
    ['bed full', 'form', 'A bed unit holds one guest.'],
    ['closed unit', 'form', 'This unit is closed, so it cannot change.'],
]);

test('an add-on is added with the charge item default amount', function () {
    $setup = actionsSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.add-ons.store', ['unit' => $setup['unit']->id]), ['charge_item_id' => $item->id, 'quantity' => 2])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Add-on added.']);

    $addOn = UnitAddOn::query()->sole();

    expect($addOn->quantity)->toBe(2)
        ->and($addOn->unit_amount)->toBe('50.00')
        ->and(Activity::query()->where('event', 'reservation.add_on_added')->count())->toBe(1);
});

test('a refused add-on shows an inline error and writes nothing', function (array $itemState, array $payload, string $field, string $message) {
    $setup = actionsSetup();
    $setup['unit']->forceFill(['arrival_date' => '2026-10-05'])->save();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00', ...$itemState]);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.add-ons.store', ['unit' => $setup['unit']->id]), ['charge_item_id' => $item->id, ...$payload])
        ->assertSessionHasErrors([$field => $message]);

    expect(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'a service date outside the stay' => [['charging_unit' => 'per_stay'], ['service_date' => '2026-11-30'], 'service_date', 'The service date must be within the stay.'],
    'a service date in the past' => [['charging_unit' => 'per_stay'], ['service_date' => '2026-10-09'], 'service_date', 'The service date cannot be before today.'],
    'a quantity out of range' => [[], ['quantity' => 1000], 'quantity', 'Enter a quantity from 1 to 999.'],
    'another amount on a fixed item' => [['allow_override' => false], ['unit_amount' => '10.00'], 'unit_amount', 'This item does not allow a different amount.'],
    'a charge item of another property' => [['property_id' => Property::factory()], [], 'charge_item_id', 'This charge item is not available.'],
]);

test('an add-on amount must look like money', function () {
    $setup = actionsSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.add-ons.store', ['unit' => $setup['unit']->id]), ['charge_item_id' => $item->id, 'unit_amount' => '1,5'])
        ->assertSessionHasErrors(['unit_amount' => 'Enter an amount such as 25.00.']);
});

test('an add-on is removed with a toast and a log', function () {
    $setup = actionsSetup();
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id]);

    $this->actingAs($setup['user'])
        ->delete(actionsRoute($setup, 'reservations.units.add-ons.destroy', ['unit' => $setup['unit']->id, 'addOn' => $addOn->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Add-on removed.']);

    expect(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.add_on_removed')->count())->toBe(1);
});

test('removing an add-on of a closed unit is an error toast and keeps the row', function () {
    $setup = actionsSetup();
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id]);
    $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();

    $this->actingAs($setup['user'])
        ->delete(actionsRoute($setup, 'reservations.units.add-ons.destroy', ['unit' => $setup['unit']->id, 'addOn' => $addOn->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This add-on cannot be removed. It is already gone, or its unit is closed.']);

    expect(UnitAddOn::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(0);
});

test('the page tells the buttons apart and sends the form lists only when asked', function () {
    $setup = actionsSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Breakfast']);
    RoomType::factory()->for($setup['property'])->create(['archived_at' => now()]);
    RatePlan::factory()->for($setup['property'])->create(['archived_at' => now()]);
    ChargeItem::factory()->inactive()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])
        ->get(actionsRoute($setup, 'reservations.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', true)
            ->where('canOverrideRestrictions', false)
            ->missing('addUnitOptions')
            ->missing('chargeItems'));

    $this->get(actionsRoute($setup, 'reservations.show'), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'addUnitOptions,chargeItems', 'X-Inertia-Version' => inertia()->getVersion()])
        ->assertOk()
        ->assertJsonCount(1, 'props.addUnitOptions.roomTypes')
        ->assertJsonPath('props.addUnitOptions.ratePlans.0.roomTypeIds', [$setup['roomType']->id])
        ->assertJsonCount(1, 'props.addUnitOptions.ratePlans')
        ->assertJsonPath('props.chargeItems.0.name', $item->name)
        ->assertJsonCount(1, 'props.chargeItems');
});

test('the page hides the buttons from a view-only role', function () {
    $setup = actionsSetup(DefaultRoleEnum::Finance);

    $this->actingAs($setup['user'])
        ->get(actionsRoute($setup, 'reservations.show'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
});

test('guest search finds guests of the property by name, email or phone', function () {
    $setup = actionsSetup();
    Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Faiz', 'phone' => '+60123456789', 'email' => 'faiz@example.com']);
    Guest::factory()->vip()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Zulkifli']);
    Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Tan Wei Ming']);
    Guest::factory()->create(['full_name' => 'Ahmad Other Property']);
    $this->actingAs($setup['user']);

    $byName = $this->getJson(route('guests.search', ['property' => $setup['property']->code, 'q' => 'ahmad']))->assertOk();

    expect(collect($byName->json('guests'))->pluck('fullName')->all())->toBe(['Ahmad Faiz', 'Ahmad Zulkifli'])
        ->and($byName->json('guests.1.isVip'))->toBeTrue();

    $this->getJson(route('guests.search', ['property' => $setup['property']->code, 'q' => 'faiz@']))->assertJsonCount(1, 'guests');
    $this->getJson(route('guests.search', ['property' => $setup['property']->code, 'q' => '0123456']))->assertJsonCount(1, 'guests');
});

test('guest search leaves out merged and anonymized guests, waits for two characters and stops at eight', function () {
    $setup = actionsSetup();
    $property = $setup['property'];
    Guest::factory()->count(10)->sequence(fn ($sequence) => ['full_name' => 'Lim '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create(['property_id' => $property->id]);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Lim Merged', 'merged_into_id' => Guest::factory()->create(['property_id' => $property->id])->id]);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Lim Anonymized', 'anonymized_at' => now()]);
    $this->actingAs($setup['user']);

    $found = $this->getJson(route('guests.search', ['property' => $property->code, 'q' => 'lim']))->assertOk();

    expect($found->json('guests'))->toHaveCount(8)
        ->and(collect($found->json('guests'))->pluck('fullName')->contains('Lim Merged'))->toBeFalse()
        ->and(collect($found->json('guests'))->pluck('fullName')->contains('Lim Anonymized'))->toBeFalse();

    $this->getJson(route('guests.search', ['property' => $property->code, 'q' => 'l']))->assertJsonCount(0, 'guests');
    $this->getJson(route('guests.search', ['property' => $property->code]))->assertJsonCount(0, 'guests');
});

test('guest search needs guests.view', function () {
    $setup = actionsSetup(DefaultRoleEnum::Housekeeping);

    $this->actingAs($setup['user'])
        ->getJson(route('guests.search', ['property' => $setup['property']->code, 'q' => 'ahmad']))
        ->assertForbidden();
});

test('guest search results carry the profile a form fills from, with the blacklist reason', function () {
    $setup = actionsSetup();
    Guest::factory()->create([
        'property_id' => $setup['property']->id,
        'full_name' => 'Ahmad Faiz',
        'nationality_code' => 'MY',
        'date_of_birth' => '1990-05-04',
        'gender' => 'male',
        'city' => 'Ipoh',
        'address_line1' => '1 Jalan Bunga',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Damaged room',
    ]);

    $this->actingAs($setup['user'])
        ->getJson(route('guests.search', ['property' => $setup['property']->code, 'q' => 'ahmad']))
        ->assertOk()
        ->assertJsonPath('guests.0.isBlacklisted', true)
        ->assertJsonPath('guests.0.profile', [
            'nationalityCode' => 'MY',
            'dateOfBirth' => '1990-05-04',
            'gender' => 'male',
            'preferredLocale' => null,
            'addressLine1' => '1 Jalan Bunga',
            'addressLine2' => null,
            'city' => 'Ipoh',
            'state' => null,
            'postalCode' => null,
            'countryCode' => 'MY',
            'blacklistReason' => 'Damaged room',
        ]);
});
