<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionCategory;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

function boatDepartureEnable(Property $property): void
{
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    Feature::for($property)->activate(Boats::class);
}

/**
 * A Transport charge item, which is what the Fare select lists.
 */
function boatDepartureTransportFare(Property $property, array $attributes = []): ChargeItem
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function boatDeparturePayload(array $overrides = []): array
{
    return array_merge([
        'direction' => BoatDirection::In->value,
        'leaves_at' => '09:00',
        'arrives_at' => '09:45',
        'pickup_point' => 'Kuala Besut jetty',
        'seats' => 20,
        'charge_item_id' => null,
        'active' => true,
    ], $overrides);
}

test('the Boats routes return 404 and the settings item is hidden while the feature is off', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)->get(route('settings.boats', $property->code))->assertNotFound();
    $this->actingAs($owner)->get(route('settings.boats.departures.index', $property->code))->assertNotFound();
    $this->post(route('settings.boats.departures.store', $property->code), boatDeparturePayload())->assertNotFound();

    expect(BoatDeparture::query()->count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('settings.meal-periods', $property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('navigation.contexts.3.groups.0.items.5'));

    boatDepartureEnable($property);

    $this->actingAs($owner)
        ->get(route('settings.boats.departures.index', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/boats/departures')
            ->where('navigation.contexts.3.groups.0.items.5.title', 'Boats')
            ->where('navigation.contexts.3.groups.0.items.5.active', true));
});

test('the Boats settings item opens the departures', function () {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);

    $this->actingAs($owner)
        ->get(route('settings.boats', $owner->property->code))
        ->assertRedirect(route('settings.boats.departures.index', $owner->property->code));
});

test('staff without settings access are refused and another property\'s departure is a 404', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $other = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);
    boatDepartureEnable($frontDesk->property);
    $otherDeparture = BoatDeparture::factory()->for($other->property)->create();

    $this->actingAs($frontDesk)
        ->get(route('settings.boats.departures.index', $frontDesk->property->code))
        ->assertForbidden();

    $this->actingAs($owner)
        ->put(route('settings.boats.departures.update', [
            'property' => $owner->property->code,
            'boatDeparture' => $otherDeparture->id,
        ]), boatDeparturePayload())
        ->assertNotFound();

    expect($otherDeparture->refresh()->leaves_at)->toBe('09:00:00');
});

test('a departure is added available, with its direction, and logged', function () {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $owner->property->code), boatDeparturePayload(['direction' => 'out', 'leaves_at' => '12:00', 'arrives_at' => '12:45']))
        ->assertRedirect(route('settings.boats.departures.index', $owner->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat departure added.']);

    $departure = BoatDeparture::query()->sole();

    expect($departure->property_id)->toBe($owner->property->id)
        ->and($departure->direction)->toBe(BoatDirection::Out)
        ->and($departure->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'boat_departure.created')->count())->toBe(1);
});

test('the departures are sorted by leave time with their fares, and only Transport items are fares', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $fare = boatDepartureTransportFare($property, ['name' => 'Ferry', 'active' => false]);
    ChargeItem::factory()->for($property)->create(['name' => 'Breakfast']);
    BoatDeparture::factory()->for($property)->create(['leaves_at' => '13:00', 'arrives_at' => '13:45']);
    BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '12:00', 'arrives_at' => '12:45', 'charge_item_id' => $fare->id]);
    BoatDeparture::factory()->for($property)->create(['leaves_at' => '09:00', 'arrives_at' => '09:45']);

    $this->actingAs($owner)
        ->get(route('settings.boats.departures.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/boats/departures')
            ->has('departures', 3)
            ->where('departures.0.leavesAt', '09:00')
            ->where('departures.1.leavesAt', '12:00')
            ->where('departures.1.direction', 'out')
            ->where('departures.1.fare.name', 'Ferry')
            ->where('departures.1.fare.active', false)
            ->where('departures.0.fare', null)
            ->has('fares', 1)
            ->where('fares.0.name', 'Ferry')
            ->where('directions.0', ['value' => 'in', 'label' => 'Boat in']));
});

test('each departure lists the meals its property-side time allows, from the active meal periods', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $property->mealPeriods()->delete();
    MealPeriod::factory()->for($property)->create(['name' => 'Dinner', 'starts_at' => '19:00', 'ends_at' => '22:00']);
    MealPeriod::factory()->for($property)->create(['name' => 'Breakfast', 'starts_at' => '07:00', 'ends_at' => '10:00']);
    MealPeriod::factory()->for($property)->create(['name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '14:30']);
    MealPeriod::factory()->for($property)->create(['name' => 'Hi-Tea', 'starts_at' => '16:00', 'ends_at' => '17:30']);
    MealPeriod::factory()->for($property)->inactive()->create(['name' => 'Supper', 'starts_at' => '22:00', 'ends_at' => '23:00']);
    BoatDeparture::factory()->for($property)->create(['leaves_at' => '11:45', 'arrives_at' => '12:30']);
    BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '12:00', 'arrives_at' => '12:45']);
    BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '14:30', 'arrives_at' => '15:15']);
    BoatDeparture::factory()->for($property)->create(['leaves_at' => '15:00', 'arrives_at' => '15:30']);

    $this->actingAs($owner)
        ->get(route('settings.boats.departures.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mealPeriods', fn ($mealPeriods) => collect($mealPeriods)->pluck('name')->all() === ['Breakfast', 'Lunch', 'Hi-Tea', 'Dinner'])
            ->where('departures.0.meals', ['Lunch', 'Hi-Tea', 'Dinner'])
            ->where('departures.1.meals', ['Breakfast'])
            ->where('departures.2.meals', ['Breakfast', 'Lunch'])
            ->where('departures.3.meals', ['Hi-Tea', 'Dinner']));
});

test('departures list no meals when the property has no active meal periods', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $property->mealPeriods()->delete();
    MealPeriod::factory()->for($property)->inactive()->create(['name' => 'Lunch', 'starts_at' => '12:00', 'ends_at' => '14:30']);
    BoatDeparture::factory()->for($property)->create(['leaves_at' => '11:45', 'arrives_at' => '12:30']);

    $this->actingAs($owner)
        ->get(route('settings.boats.departures.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('mealPeriods', 0)
            ->where('departures.0.meals', []));
});

test('a second departure in the same direction at the same time is refused', function () {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);
    BoatDeparture::factory()->for($owner->property)->create(['leaves_at' => '09:00', 'arrives_at' => '09:45']);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $owner->property->code), boatDeparturePayload())
        ->assertSessionHasErrors(['leaves_at' => 'A boat in already leaves at 09:00.']);

    expect(BoatDeparture::query()->count())->toBe(1);
});

test('the other direction may leave at the same time', function () {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);
    BoatDeparture::factory()->for($owner->property)->create(['leaves_at' => '09:00', 'arrives_at' => '09:45']);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $owner->property->code), boatDeparturePayload(['direction' => 'out']))
        ->assertSessionHasNoErrors();

    expect(BoatDeparture::query()->count())->toBe(2);
});

test('arrival must be after departure and seats must be above zero', function () {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);
    $url = route('settings.boats.departures.store', $owner->property->code);

    $this->actingAs($owner)
        ->post($url, boatDeparturePayload(['arrives_at' => '08:30']))
        ->assertSessionHasErrors(['arrives_at' => 'Arrives must be after Leaves.']);

    $this->actingAs($owner)
        ->post($url, boatDeparturePayload(['seats' => 0]))
        ->assertSessionHasErrors('seats');

    expect(BoatDeparture::query()->count())->toBe(0);
});

test('a fare must be an available Transport item of this property', function (callable $makeFare) {
    $owner = User::factory()->owner()->create();
    boatDepartureEnable($owner->property);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $owner->property->code), boatDeparturePayload(['charge_item_id' => $makeFare($owner->property)]))
        ->assertSessionHasErrors(['charge_item_id' => 'Choose a fare from this property.']);

    expect(BoatDeparture::query()->count())->toBe(0);
})->with([
    'not a Transport item' => [fn (Property $property) => ChargeItem::factory()->for($property)->create()->id],
    'another property\'s fare' => [fn () => boatDepartureTransportFare(Property::factory()->create())->id],
]);

test('a new departure cannot take an unavailable fare, and says to turn the fare on', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $fare = boatDepartureTransportFare($property, ['active' => false]);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $property->code), boatDeparturePayload(['charge_item_id' => $fare->id]))
        ->assertSessionHasErrors(['charge_item_id' => 'Turn the fare Speedboat on first.']);

    expect(BoatDeparture::query()->count())->toBe(0);
});

test('a departure keeps its current fare when it is unavailable and that fare becomes unavailable', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $fare = boatDepartureTransportFare($property);
    $departure = BoatDeparture::factory()->for($property)->create(['charge_item_id' => $fare->id, 'active' => false]);
    $fare->update(['active' => false]);

    $this->actingAs($owner)
        ->put(route('settings.boats.departures.update', ['property' => $property->code, 'boatDeparture' => $departure->id]), boatDeparturePayload(['charge_item_id' => $fare->id, 'seats' => 18, 'active' => false]))
        ->assertSessionHasNoErrors();

    expect($departure->refresh()->charge_item_id)->toBe($fare->id)
        ->and($departure->seats)->toBe(18);
});

test('an available departure cannot keep an unavailable fare, on turn-on or an edit that sends active', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $fare = boatDepartureTransportFare($property, ['active' => false]);
    $departure = BoatDeparture::factory()->for($property)->create(['charge_item_id' => $fare->id, 'active' => false]);

    $this->actingAs($owner)
        ->put(route('settings.boats.departures.update', ['property' => $property->code, 'boatDeparture' => $departure->id]), boatDeparturePayload(['charge_item_id' => $fare->id, 'active' => true]))
        ->assertSessionHasErrors(['active' => 'Turn the fare Speedboat on first.']);

    expect($departure->refresh()->active)->toBeFalse();
});

test('a fare that adds itself to every new booking cannot be a departure\'s fare', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $fare = boatDepartureTransportFare($property, ['auto_add' => true]);

    $this->actingAs($owner)
        ->post(route('settings.boats.departures.store', $property->code), boatDeparturePayload(['charge_item_id' => $fare->id]))
        ->assertSessionHasErrors(['charge_item_id' => 'This fare is added to every new booking. Turn that off first.']);

    expect(BoatDeparture::query()->count())->toBe(0);
});

test('turning a departure off saves at once with a toast, and on again', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $departure = BoatDeparture::factory()->for($property)->create();
    $url = route('settings.boats.departures.update', ['property' => $property->code, 'boatDeparture' => $departure->id]);

    $this->actingAs($owner)
        ->put($url, boatDeparturePayload(['active' => false]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '09:00 boat in is unavailable.']);

    expect($departure->refresh()->active)->toBeFalse();

    $this->actingAs($owner)
        ->put($url, boatDeparturePayload(['active' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '09:00 boat in is available.']);

    expect($departure->refresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'boat_departure.updated')->count())->toBe(2);
});

test('editing logs the before and after times, and a save that changes nothing is not logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $departure = BoatDeparture::factory()->for($property)->create();
    $url = route('settings.boats.departures.update', ['property' => $property->code, 'boatDeparture' => $departure->id]);

    $this->actingAs($owner)
        ->put($url, boatDeparturePayload())
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat departure saved.']);

    expect(Activity::query()->where('event', 'boat_departure.updated')->count())->toBe(0);

    $this->actingAs($owner)->put($url, boatDeparturePayload(['leaves_at' => '09:30', 'arrives_at' => '10:15']));

    $activity = Activity::query()->where('event', 'boat_departure.updated')->sole();

    expect($activity->properties['before']['leaves_at'])->toBe('09:00')
        ->and($activity->properties['after']['leaves_at'])->toBe('09:30')
        ->and($activity->properties['after']['arrives_at'])->toBe('10:15');
});

test('deleting a departure removes it and logs the deletion', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $departure = BoatDeparture::factory()->for($property)->create();

    $this->actingAs($owner)
        ->delete(route('settings.boats.departures.destroy', ['property' => $property->code, 'boatDeparture' => $departure->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat departure deleted.']);

    expect(BoatDeparture::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'boat_departure.deleted')->count())->toBe(1);
});

test('the database enforces the departure invariants', function (array $attributes) {
    $property = Property::factory()->create();

    DB::table('boat_departures')->insert([
        'property_id' => $property->id,
        'direction' => 'in',
        'leaves_at' => '09:00',
        'arrives_at' => '09:45',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
})->throws(QueryException::class)->with([
    'arrival not after departure' => [['arrives_at' => '08:30']],
    'seats not above zero' => [['seats' => 0]],
    'unknown direction' => [['direction' => 'sideways']],
]);

test('the database refuses a second departure at the same time in one direction', function () {
    $property = Property::factory()->create();
    BoatDeparture::factory()->for($property)->create();

    BoatDeparture::factory()->for($property)->create();
})->throws(QueryException::class);

test('the property-side time is the arrival for boat in and the departure for boat out', function () {
    $departure = BoatDeparture::factory()->make(['leaves_at' => '09:00:00', 'arrives_at' => '09:45:00']);

    expect(BoatDirection::In->propertyTime($departure))->toBe('09:45:00')
        ->and(BoatDirection::Out->propertyTime($departure))->toBe('09:00:00');
});

test('a turn-off sent as a form field, with active "0", saves it as unavailable', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    $departure = BoatDeparture::factory()->for($property)->create();
    $url = route('settings.boats.departures.update', ['property' => $property->code, 'boatDeparture' => $departure->id]);

    $this->actingAs($owner)
        ->put($url, ['direction' => 'in', 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => '', 'seats' => '20', 'charge_item_id' => '', 'active' => '0'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '09:00 boat in is unavailable.']);

    expect($departure->refresh()->active)->toBeFalse();
});

test('the departures page counts the bookings whose upcoming trips use each departure, once per booking', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    boatDepartureEnable($property);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);
    $departure = BoatDeparture::factory()->for($property)->create(['leaves_at' => '09:00', 'arrives_at' => '09:45']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unitA = ReservationUnit::factory()->for($reservation)->create(['property_id' => $property->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-23']);
    $unitB = ReservationUnit::factory()->for($reservation)->create(['property_id' => $property->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-23']);
    $past = ReservationUnit::factory()->for($reservation)->create(['property_id' => $property->id, 'arrival_date' => '2026-10-05', 'departure_date' => '2026-10-08']);

    foreach ([$unitA, $unitB, $past] as $unit) {
        UnitBoatTrip::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id, 'direction' => BoatDirection::In, 'mode' => BoatMode::Scheduled, 'boat_departure_id' => $departure->id]);
    }

    $this->actingAs($owner)
        ->get(route('settings.boats.departures.index', ['property' => $property->code]))
        ->assertInertia(fn (Assert $page) => $page->where('departures.0.upcomingBookings', 1));
});
