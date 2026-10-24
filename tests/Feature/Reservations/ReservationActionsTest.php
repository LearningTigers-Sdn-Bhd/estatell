<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Actions\Folio\CreateFolio;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentPurpose;
use App\Enums\RegistrationCardStatus;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RegistrationCard;
use App\Models\RegistrationCardTerms;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tag;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Reservations\AutoAddOns;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

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

test('adding a unit that sends room charges to the master warns about an advance left on a unit folio', function () {
    $setup = actionsSetup();
    $folio = app(CreateFolio::class)($setup['reservation'], $setup['unit']);
    Payment::factory()->for($folio)->create(['purpose' => PaymentPurpose::Advance, 'amount' => '200.00']);

    $this->actingAs($setup['user'])
        ->from(actionsRoute($setup, 'reservations.show'))
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup))
        ->assertRedirect(actionsRoute($setup, 'reservations.show'))
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => "Unit added. Room charges now go to the Master folio, but a unit's own folio holds a MYR 200.00 advance. Transfer its room charges back to it, or refund the advance.",
        ]);

    expect($setup['reservation']->units()->count())->toBe(2);
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

test('a new primary on a checked-out unit with a tax warning flashes a warning toast', function () {
    $setup = actionsSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()])->save();
    $setup['reservation']->booker->forceFill(['nationality_code' => 'MY'])->save();
    Tax::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Tourism tax', 'foreign_guests_only' => true]);
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'nationality_code' => 'SG']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['guest_id' => $guest->id])
        ->assertInertiaFlash('toast', ['type' => 'warning', 'message' => "Guest added. Tourism tax was charged on the booker's nationality (Malaysia). If it is different for this guest, correct it on the folio."]);
});

test('a new guest is created and attached in one save', function () {
    $setup = actionsSetup();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['full_name' => 'Siti Aminah', 'phone' => '+60121234567', 'city' => 'Ipoh'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Siti Aminah added to the unit.']);

    $guest = Guest::query()->where('full_name', 'Siti Aminah')->sole();

    expect($guest->property_id)->toBe($setup['property']->id)
        ->and($guest->phone)->toBe('+60121234567')
        ->and($guest->city)->toBe('Ipoh')
        ->and(UnitGuest::query()->sole()->guest_id)->toBe($guest->id);
});

test('a new guest is not left behind when the unit refuses the attach', function () {
    $setup = actionsSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['full_name' => 'Siti Aminah'])
        ->assertSessionHasErrors(['form' => 'This unit is closed, so it cannot change.']);

    expect(Guest::query()->where('full_name', 'Siti Aminah')->exists())->toBeFalse()
        ->and(UnitGuest::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'guest.created')->count())->toBe(0);
});

test('a picked guest is changed only when the profile update is on, and keeps its tags', function (bool $update, string $city) {
    $setup = actionsSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Faiz', 'city' => 'Penang']);
    $tag = Tag::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Corporate']);
    $guest->tags()->attach($tag);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['guest_id' => $guest->id, 'update_profile' => $update, 'full_name' => 'Ahmad Faiz', 'city' => 'Ipoh'])
        ->assertSessionHasNoErrors();

    expect($guest->refresh()->city)->toBe($city)
        ->and($guest->tags()->pluck('name')->all())->toBe(['Corporate'])
        ->and(UnitGuest::query()->sole()->guest_id)->toBe($guest->id);
})->with([
    'update off' => [false, 'Penang'],
    'update on' => [true, 'Ipoh'],
]);

test('staff without guests.manage may only attach an existing guest as it is', function (array $body, string $key, string $message) {
    $setup = actionsSetup();
    $user = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($user, ['reservations.view', 'reservations.manage', 'guests.view']);
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $before = Guest::query()->count();

    $this->actingAs($user)
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), array_replace(['guest_id' => $guest->id], $body))
        ->assertSessionHasErrors([$key => $message]);

    expect(UnitGuest::query()->count())->toBe(0)->and(Guest::query()->count())->toBe($before);
})->with([
    'a new guest' => [['guest_id' => null, 'full_name' => 'New Person'], 'full_name', 'Pick an existing guest.'],
    'a profile update' => [['update_profile' => true, 'full_name' => 'Changed'], 'update_profile', "You may not change a guest's profile."],
]);

test('a new guest needs a name', function () {
    $setup = actionsSetup();
    $before = Guest::query()->count();

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.store', ['unit' => $setup['unit']->id]), ['phone' => '+60121234567'])
        ->assertSessionHasErrors(['full_name' => "Enter the guest's name or pick an existing guest."]);

    expect(Guest::query()->count())->toBe($before);
});

test('the profile of a guest on the unit is saved without moving the guest', function () {
    $setup = actionsSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Ahmad Faiz', 'city' => 'Penang']);
    $guest->tags()->attach(Tag::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Corporate']));
    $link = UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'guest_id' => $guest->id, 'is_primary' => true]);

    $this->actingAs($setup['user'])
        ->put(actionsRoute($setup, 'reservations.units.guests.update', ['unit' => $setup['unit']->id, 'guestId' => $guest->id]), ['full_name' => 'Ahmad Faiz bin Ali', 'city' => 'Ipoh'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ahmad Faiz bin Ali saved.']);

    expect($guest->refresh()->full_name)->toBe('Ahmad Faiz bin Ali')
        ->and($guest->city)->toBe('Ipoh')
        ->and($guest->tags()->count())->toBe(1)
        ->and($link->refresh()->is_primary)->toBeTrue()
        ->and(UnitGuest::query()->count())->toBe(1);
});

test('only a guest on the unit can be saved from it, and only with guests.manage', function () {
    $setup = actionsSetup();
    $stranger = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $url = actionsRoute($setup, 'reservations.units.guests.update', ['unit' => $setup['unit']->id, 'guestId' => $stranger->id]);

    $this->actingAs($setup['user'])->put($url, ['full_name' => 'Changed'])->assertNotFound();

    $viewer = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($viewer, ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($viewer)->put($url, ['full_name' => 'Changed'])->assertForbidden();

    expect($stranger->refresh()->full_name)->not->toBe('Changed');
});

/**
 * A guest on the unit of the setup.
 *
 * @param  array<string, mixed>  $overrides
 */
function actionsOccupant(array $setup, array $overrides = [], array $guestAttributes = []): UnitGuest
{
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, ...$guestAttributes]);

    return UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'guest_id' => $guest->id, ...$overrides]);
}

test('a guest is replaced in place and the primary role stays', function () {
    $setup = actionsSetup();
    $old = actionsOccupant($setup, ['is_primary' => true], ['full_name' => 'Wrong Person']);
    $new = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Right Person']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), ['guest_id' => $new->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Right Person is now on the unit.']);

    $row = UnitGuest::query()->sole();
    $log = Activity::query()->where('event', 'reservation.guest_replaced')->sole();

    expect($row->id)->toBe($old->id)
        ->and($row->guest_id)->toBe($new->id)
        ->and($row->is_primary)->toBeTrue()
        ->and($log->properties['previous_guest_id'])->toBe($old->guest_id)
        ->and($log->properties['guest_id'])->toBe($new->id);
});

test('a new person can replace a guest and is created with the change', function () {
    $setup = actionsSetup();
    $old = actionsOccupant($setup);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), ['full_name' => 'Siti Aminah', 'city' => 'Ipoh'])
        ->assertSessionHasNoErrors();

    $created = Guest::query()->where('full_name', 'Siti Aminah')->sole();

    expect(UnitGuest::query()->sole()->guest_id)->toBe($created->id);
});

test('a replace is refused and writes nothing', function (string $case, string $key, string $message) {
    $setup = actionsSetup();
    $old = actionsOccupant($setup, ['is_primary' => true]);
    $newId = Guest::factory()->create(['property_id' => $setup['property']->id])->id;
    $body = ['guest_id' => $newId];

    match ($case) {
        'closed unit' => $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save(),
        'same guest' => $body = ['guest_id' => $old->guest_id],
        'already on unit' => UnitGuest::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'guest_id' => $newId]),
        'merged guest' => Guest::query()->whereKey($newId)->update(['merged_into_id' => $old->guest_id]),
        'signed card' => RegistrationCard::factory()->create([
            'unit_guest_id' => $old->id,
            'status' => RegistrationCardStatus::Signed,
            'signed_at' => now(),
            'terms_id' => RegistrationCardTerms::factory()->create()->id,
            'snapshot' => ['guest' => 'signed'],
            'number' => 'RC-1',
        ]),
        'void card' => RegistrationCard::factory()->create(['unit_guest_id' => $old->id, 'status' => RegistrationCardStatus::Void, 'void_reason' => 'Typo', 'voided_at' => now()]),
    };

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), $body)
        ->assertSessionHasErrors([$key => $message]);

    expect(UnitGuest::query()->where('guest_id', $old->guest_id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'reservation.guest_replaced')->count())->toBe(0);
})->with([
    ['closed unit', 'form', 'This unit is closed, so it cannot change.'],
    ['same guest', 'guest_id', 'This guest already stays in this unit.'],
    ['already on unit', 'guest_id', 'This guest already stays in this unit.'],
    ['merged guest', 'guest_id', 'This guest cannot be found.'],
    ['signed card', 'form', 'This guest has a registration card on this unit, so they cannot be changed.'],
    ['void card', 'form', 'This guest has a registration card on this unit, so they cannot be changed.'],
]);

test('a new guest is not left behind when the replace is refused', function () {
    $setup = actionsSetup();
    $old = actionsOccupant($setup);
    RegistrationCard::factory()->create(['unit_guest_id' => $old->id, 'status' => RegistrationCardStatus::Void, 'void_reason' => 'Typo', 'voided_at' => now()]);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), ['full_name' => 'Siti Aminah'])
        ->assertSessionHasErrors('form');

    expect(Guest::query()->where('full_name', 'Siti Aminah')->exists())->toBeFalse();
});

test('a draft registration card is deleted with the replace or the remove', function (string $route, array $body, string $method) {
    $setup = actionsSetup();
    $old = actionsOccupant($setup, ['is_primary' => true]);
    $card = RegistrationCard::factory()->create(['unit_guest_id' => $old->id]);

    if (isset($body['replace'])) {
        $body = ['guest_id' => Guest::factory()->create(['property_id' => $setup['property']->id])->id];
    }

    $this->actingAs($setup['user'])
        ->{$method}(actionsRoute($setup, $route, ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), $body)
        ->assertSessionHasNoErrors();

    expect(RegistrationCard::query()->whereKey($card->id)->exists())->toBeFalse();
})->with([
    'replace' => ['reservations.units.guests.replace', ['replace' => true], 'post'],
    'remove' => ['reservations.units.guests.destroy', [], 'delete'],
]);

test('removing the primary guest makes the oldest other guest primary', function () {
    $setup = actionsSetup();
    $primary = actionsOccupant($setup, ['is_primary' => true]);
    $second = actionsOccupant($setup);
    $third = actionsOccupant($setup);

    $this->actingAs($setup['user'])
        ->delete(actionsRoute($setup, 'reservations.units.guests.destroy', ['unit' => $setup['unit']->id, 'guestId' => $primary->guest_id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $primary->guest->full_name.' removed from the unit.']);

    $log = Activity::query()->where('event', 'reservation.guest_detached')->sole();

    expect(UnitGuest::query()->whereKey($primary->id)->exists())->toBeFalse()
        ->and($second->refresh()->is_primary)->toBeTrue()
        ->and($third->refresh()->is_primary)->toBeFalse()
        ->and($log->properties['was_primary'])->toBeTrue()
        ->and($log->properties['promoted_guest_id'])->toBe($second->guest_id);
});

test('removing a guest who is not primary changes no one else, and the last guest may go', function () {
    $setup = actionsSetup();
    $primary = actionsOccupant($setup, ['is_primary' => true]);
    $second = actionsOccupant($setup);

    $this->actingAs($setup['user'])->delete(actionsRoute($setup, 'reservations.units.guests.destroy', ['unit' => $setup['unit']->id, 'guestId' => $second->guest_id]));

    expect($primary->refresh()->is_primary)->toBeTrue();

    $this->actingAs($setup['user'])->delete(actionsRoute($setup, 'reservations.units.guests.destroy', ['unit' => $setup['unit']->id, 'guestId' => $primary->guest_id]));

    expect(UnitGuest::query()->count())->toBe(0);
});

test('a remove is refused with an error toast and writes nothing', function (string $case, string $message) {
    $setup = actionsSetup();
    $old = actionsOccupant($setup, ['is_primary' => true]);

    match ($case) {
        'closed unit' => $setup['unit']->forceFill(['status' => UnitStatus::NoShow])->save(),
        'void card' => RegistrationCard::factory()->create(['unit_guest_id' => $old->id, 'status' => RegistrationCardStatus::Void, 'void_reason' => 'Typo', 'voided_at' => now()]),
    };

    $this->actingAs($setup['user'])
        ->delete(actionsRoute($setup, 'reservations.units.guests.destroy', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);

    expect(UnitGuest::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.guest_detached')->count())->toBe(0);
})->with([
    ['closed unit', 'This unit is closed, so it cannot change.'],
    ['void card', 'This guest has a registration card on this unit, so they cannot be changed.'],
]);

test('replace and remove need the guest on the unit and reservations.manage', function () {
    $setup = actionsSetup();
    $stranger = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $replace = actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $stranger->id]);
    $remove = actionsRoute($setup, 'reservations.units.guests.destroy', ['unit' => $setup['unit']->id, 'guestId' => $stranger->id]);

    $this->actingAs($setup['user'])->post($replace, ['guest_id' => $stranger->id])->assertNotFound();
    $this->actingAs($setup['user'])->delete($remove)->assertNotFound();

    $finance = actionsSetup(DefaultRoleEnum::Finance);
    $url = actionsRoute($finance, 'reservations.units.guests.destroy', ['unit' => $finance['unit']->id, 'guestId' => 1]);

    $this->actingAs($finance['user'])->delete($url)->assertForbidden();
});

test('replacing the primary guest of a checked-out unit with a different foreign status warns about the tax', function () {
    $setup = actionsSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()])->save();
    $setup['reservation']->booker->forceFill(['nationality_code' => 'MY'])->save();
    Tax::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Tourism tax', 'foreign_guests_only' => true]);
    $old = actionsOccupant($setup, ['is_primary' => true], ['nationality_code' => 'MY']);
    $new = Guest::factory()->create(['property_id' => $setup['property']->id, 'nationality_code' => 'SG']);

    $this->actingAs($setup['user'])
        ->post(actionsRoute($setup, 'reservations.units.guests.replace', ['unit' => $setup['unit']->id, 'guestId' => $old->guest_id]), ['guest_id' => $new->id])
        ->assertInertiaFlash('toast', ['type' => 'warning', 'message' => "Guest added. Tourism tax was charged on the booker's nationality (Malaysia). If it is different for this guest, correct it on the folio."]);
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

test('Add unit puts the auto-add charge items on the new unit and names them in the toast', function () {
    $setup = actionsSetup();
    $fee = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Cleaning fee', 'default_amount' => '20.00', 'charging_unit' => ChargingUnit::PerStay, 'auto_add' => true, 'position' => 1]);
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Resort fee', 'default_amount' => '5.00', 'charging_unit' => ChargingUnit::PerNight, 'auto_add' => true, 'position' => 2]);
    ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '9.00', 'auto_add' => false]);
    ChargeItem::factory()->inactive()->create(['property_id' => $setup['property']->id, 'default_amount' => '9.00', 'auto_add' => true]);
    ChargeItem::factory()->create(['default_amount' => '9.00', 'auto_add' => true]);

    $this->actingAs($setup['user'])
        ->from(actionsRoute($setup, 'reservations.show'))
        ->post(actionsRoute($setup, 'reservations.units.store'), actionsUnitPayload($setup))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unit added with Cleaning fee, Resort fee.']);

    $unit = $setup['reservation']->units()->latest('id')->firstOrFail();

    expect($unit->addOns()->where('charge_item_id', $fee->id)->sole()->unit_amount)->toBe('20.00')
        ->and($unit->addOns()->where('charge_item_id', $nightly->id)->count())->toBe(2)
        ->and($unit->addOns()->count())->toBe(3);
});

test('a day use unit gets only the auto-add items that do not charge by the night', function () {
    $setup = actionsSetup();
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '5.00', 'charging_unit' => ChargingUnit::PerGuestNight, 'auto_add' => true]);
    $perStay = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '20.00', 'charging_unit' => ChargingUnit::PerStay, 'auto_add' => true]);

    $items = app(AutoAddOns::class)->items($setup['property'], StayType::DayUse);

    expect(array_map(fn (ChargeItem $item): int => $item->id, $items))->toBe([$perStay->id])
        ->and(array_map(fn (ChargeItem $item): int => $item->id, app(AutoAddOns::class)->items($setup['property'])))->toBe([$nightly->id, $perStay->id]);
});

test('the profile of a booker who does not stay is saved from the reservation', function () {
    $setup = actionsSetup();
    $booker = $setup['reservation']->booker;

    $this->actingAs($setup['user'])
        ->put(actionsRoute($setup, 'reservations.booker.update'), ['full_name' => 'Fujita Kotone Jr', 'city' => 'Osaka'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Fujita Kotone Jr saved.']);

    expect($booker->refresh()->full_name)->toBe('Fujita Kotone Jr')
        ->and($booker->city)->toBe('Osaka');
});

test('the booker can be saved only with guests.manage', function () {
    $setup = actionsSetup();
    $viewer = User::factory()->create(['property_id' => $setup['property']->id]);
    actionsRoleWith($viewer, ['reservations.view', 'reservations.manage', 'guests.view']);

    $this->actingAs($viewer)
        ->put(actionsRoute($setup, 'reservations.booker.update'), ['full_name' => 'Changed'])
        ->assertForbidden();

    expect($setup['reservation']->booker->refresh()->full_name)->not->toBe('Changed');
});
