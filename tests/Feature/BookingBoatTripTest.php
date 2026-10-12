<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\DefaultRoleEnum;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner, a property with the Boats feature on, two rooms of one room type with a plan and rates for 20 to 26 Oct,
 * a guest, a Boat in departure at 09:00 (arrives 09:45) and a Boat out departure at 12:00 (arrives at the jetty 12:45).
 *
 * @return array{user: User, property: Property, roomType: RoomType, plan: RatePlan, guest: Guest, in: BoatDeparture, out: BoatDeparture}
 */
function boatBookingSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    Feature::for($property)->activate(Boats::class);

    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($night = 0; $night < 7; $night++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => now()->setDate(2026, 10, 20 + $night)->toDateString(), 'amount' => '100.00']);
    }

    return [
        'user' => $user,
        'property' => $property,
        'roomType' => $roomType,
        'plan' => $plan,
        'guest' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789']),
        'in' => BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20]),
        'out' => BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '12:00', 'arrives_at' => '12:45', 'pickup_point' => 'Resort jetty', 'seats' => 20]),
    ];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function boatBookingLine(array $setup, array $overrides = []): array
{
    return ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => [], ...$overrides];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function boatBookingBody(array $setup, array $overrides = []): array
{
    return [
        'source' => 'phone',
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [boatBookingLine($setup)],
        ...$overrides,
    ];
}

function boatBookingStoreUrl(array $setup): string
{
    return route('reservations.store', ['property' => $setup['property']->code]);
}

/**
 * Saves a one-line booking with a Boat in trip, and returns the booked reservation.
 *
 * @param  array<string, mixed>  $setup
 */
function boatBookingWithBoatIn(array $setup): Reservation
{
    test()->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, ['boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]))
        ->assertSessionHasNoErrors();

    return Reservation::query()->sole();
}

test('a booking with a scheduled boat in sets the trip and the arrival time on every unit', function () {
    $setup = boatBookingSetup();

    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, [
            'lines' => [boatBookingLine($setup), boatBookingLine($setup)],
            'boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(ReservationUnit::query()->count())->toBe(2)
        ->and(UnitBoatTrip::query()->where('direction', BoatDirection::In)->where('boat_departure_id', $setup['in']->id)->count())->toBe(2)
        ->and(ReservationUnit::query()->pluck('arrival_time')->unique()->all())->toBe(['09:45:00']);
});

test('a booking with a charter boat out sends the typed time and the note to every unit', function () {
    $setup = boatBookingSetup();

    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, [
            'departure_time' => '12:30',
            'boat_out' => ['mode' => 'charter', 'time' => '12:30', 'note' => 'Private transfer'],
        ]))
        ->assertSessionHasNoErrors();

    expect(UnitBoatTrip::query()->sole())
        ->mode->toBe(BoatMode::Charter)
        ->note->toBe('Private transfer')
        ->and(ReservationUnit::query()->sole()->departure_time)->toBe('12:30:00');
});

test('a refused boat in rolls the whole booking back and names the Stay row', function () {
    $setup = boatBookingSetup();
    $setup['in']->update(['active' => false]);

    $this->actingAs($setup['user'])
        ->from(route('reservations.new', ['property' => $setup['property']->code]))
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, ['boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]))
        ->assertSessionHasErrors(['boat_in' => 'This boat is not available for this direction. Choose another.']);

    expect(Reservation::query()->count())->toBe(0)
        ->and(ReservationUnit::query()->count())->toBe(0)
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a boat in while the Boats feature is off is refused on the Stay row', function () {
    $setup = boatBookingSetup();
    Feature::for($setup['property'])->deactivate(Boats::class);

    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, ['boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]))
        ->assertSessionHasErrors(['boat_in' => 'Boats is not on for this property.']);

    expect(Reservation::query()->count())->toBe(0);
});

test('a day use booking takes no boat trip', function () {
    $setup = boatBookingSetup();

    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, [
            'stay_type' => 'day_use',
            'start_time' => '10:00',
            'departure_date' => null,
            'lines' => [boatBookingLine($setup, ['hours' => 4, 'room_id' => null])],
            'boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        ]))
        ->assertSessionHasErrors('boat_in');

    expect(Reservation::query()->count())->toBe(0);
});

test('the booking with a boat trip runs with lazy loading prevented', function () {
    $setup = boatBookingSetup();
    Model::preventLazyLoading();

    try {
        $this->actingAs($setup['user'])
            ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, ['boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect(UnitBoatTrip::query()->count())->toBe(1);
});

test('add unit copies the boat trips of the first unit to the new unit', function () {
    $setup = boatBookingSetup();
    $reservation = boatBookingWithBoatIn($setup);

    $this->actingAs($setup['user'])
        ->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $reservation->id]), [
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'adults' => 2,
            'child_ages' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unit added.']);

    expect(UnitBoatTrip::query()->where('boat_departure_id', $setup['in']->id)->count())->toBe(2)
        ->and(ReservationUnit::query()->latest('id')->first()->arrival_time)->toBe('09:45:00');
});

test('add unit skips a departure that is no longer available and names it in the toast', function () {
    $setup = boatBookingSetup();
    $reservation = boatBookingWithBoatIn($setup);
    $setup['in']->update(['active' => false]);

    $this->actingAs($setup['user'])
        ->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $reservation->id]), [
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'adults' => 2,
            'child_ages' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unit added. Boat in 09:00 was skipped. It is no longer available.']);

    expect(ReservationUnit::query()->count())->toBe(2)
        ->and(UnitBoatTrip::query()->count())->toBe(1);
});

test('add unit copies a charter boat out with its time and note', function () {
    $setup = boatBookingSetup();
    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, ['departure_time' => '12:30', 'boat_out' => ['mode' => 'charter', 'time' => '12:30', 'note' => 'Private transfer']]))
        ->assertSessionHasNoErrors();
    $reservation = Reservation::query()->sole();

    $this->actingAs($setup['user'])
        ->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $reservation->id]), [
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'adults' => 2,
            'child_ages' => [],
        ])
        ->assertSessionHasNoErrors();

    $copied = UnitBoatTrip::query()->where('direction', BoatDirection::Out)->where('mode', BoatMode::Charter)->get();

    expect($copied)->toHaveCount(2)
        ->and($copied->pluck('note')->unique()->all())->toBe(['Private transfer'])
        ->and(ReservationUnit::query()->latest('id')->first()->departure_time)->toBe('12:30:00');
});

test('add unit copies nothing and is not refused while the Boats feature is off', function () {
    $setup = boatBookingSetup();
    $reservation = boatBookingWithBoatIn($setup);
    Feature::for($setup['property'])->deactivate(Boats::class);

    $this->actingAs($setup['user'])
        ->post(route('reservations.units.store', ['property' => $setup['property']->code, 'reservation' => $reservation->id]), [
            'room_type_id' => $setup['roomType']->id,
            'rate_plan_id' => $setup['plan']->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'adults' => 2,
            'child_ages' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Unit added.']);

    expect(ReservationUnit::query()->count())->toBe(2)
        ->and(UnitBoatTrip::query()->count())->toBe(1);
});

test('the boat seats count the guests of each trip by direction and date', function () {
    $setup = boatBookingSetup();
    $late = BoatDeparture::factory()->for($setup['property'])->create(['direction' => BoatDirection::In, 'leaves_at' => '13:00', 'arrives_at' => '13:45', 'seats' => null, 'active' => false]);

    $this->actingAs($setup['user'])
        ->post(boatBookingStoreUrl($setup), boatBookingBody($setup, [
            'lines' => [boatBookingLine($setup, ['child_ages' => [4]]), boatBookingLine($setup)],
            'boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        ]))
        ->assertSessionHasNoErrors();

    $url = route('reservations.boat-seats', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->getJson($url.'?direction=in&date=2026-10-20')
        ->assertOk()
        ->assertJsonPath('seats', [(string) $setup['in']->id => 5]);

    $this->actingAs($setup['user'])->getJson($url.'?direction=out&date=2026-10-22')
        ->assertOk()
        ->assertJsonPath('seats', [(string) $setup['out']->id => 0]);

    expect($late->fresh()->active)->toBeFalse();
});

test('the boat seats route is 404 while the Boats feature is off', function () {
    $setup = boatBookingSetup();
    Feature::for($setup['property'])->deactivate(Boats::class);

    $this->actingAs($setup['user'])
        ->getJson(route('reservations.boat-seats', ['property' => $setup['property']->code]).'?direction=in&date=2026-10-20')
        ->assertNotFound();
});
