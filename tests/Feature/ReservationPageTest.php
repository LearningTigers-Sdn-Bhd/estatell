<?php

use App\Enums\BillingMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitChild;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function pageOwner(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();
    PropertySetting::factory()->for($user->property)->create();

    return [$user, $user->property];
}

/**
 * A staff user whose only role holds the given permissions.
 *
 * @param  list<string>  $permissions
 * @return array{0: User, 1: Property}
 */
function pageStaffWith(array $permissions): array
{
    $user = User::factory()->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Viewer']);
    $previous = getPermissionsTeamId();
    setPermissionsTeamId($property->id);
    $role->givePermissionTo($permissions);
    $user->assignRole($role);
    setPermissionsTeamId($previous);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return [$user, $property];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pageReservation(Property $property, array $attributes = []): Reservation
{
    return Reservation::factory()->create(['property_id' => $property->id, ...$attributes]);
}

/**
 * A unit with nights priced at 100.00, from 2026-10-12 for three nights unless the attributes say otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function pageUnit(Reservation $reservation, array $attributes = []): ReservationUnit
{
    return ReservationUnit::factory()->for($reservation)->withNights()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-15',
        ...$attributes,
    ]);
}

function pageUrl(Property $property, Reservation $reservation, string $route = 'reservations.show', array $query = []): string
{
    return route($route, ['property' => $property->code, 'reservation' => $reservation->id, ...$query]);
}

test('the reservation page and its tabs are open to reservations.view roles only', function (DefaultRoleEnum $role, int $status) {
    [$user, $property] = pageOwner($role);
    $reservation = pageReservation($property);
    pageUnit($reservation);

    foreach (['reservations.show', 'reservations.guests', 'reservations.add-ons', 'reservations.activity'] as $route) {
        $this->actingAs($user)->get(pageUrl($property, $reservation, $route))->assertStatus($status);
    }
})->with([
    [DefaultRoleEnum::Owner, 200],
    [DefaultRoleEnum::Manager, 200],
    [DefaultRoleEnum::FrontDesk, 200],
    [DefaultRoleEnum::Finance, 200],
    [DefaultRoleEnum::Housekeeping, 403],
]);

test('another property\'s reservation or code returns 404', function () {
    [$user, $property] = pageOwner();
    $foreign = pageReservation(Property::factory()->create());

    foreach (['reservations.show', 'reservations.guests', 'reservations.add-ons', 'reservations.activity'] as $route) {
        $this->actingAs($user)->get(pageUrl($property, $foreign, $route))->assertNotFound();
        $this->get(pageUrl($foreign->property, $foreign, $route))->assertNotFound();
    }
});

test('the header and summary carry the reservation figures from units that hold inventory', function () {
    [$user, $property] = pageOwner();
    $booker = Guest::factory()->vip()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789', 'email' => 'tan@example.com']);
    $reservation = pageReservation($property, ['group_name' => 'Tan trip', 'booker_guest_id' => $booker->id, 'billing_mode' => BillingMode::EachPays, 'external_reference' => 'OTA-1']);
    pageUnit($reservation);
    pageUnit($reservation, ['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-17']);
    pageUnit($reservation, ['status' => 'cancelled', 'arrival_date' => '2026-10-01', 'departure_date' => '2026-10-02']);

    $this->actingAs($user)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->component('reservations/show')
        ->where('tab', 'units')
        ->where('currency', 'MYR')
        ->where('reservation.reference', $reservation->reference)
        ->where('reservation.title', 'Tan trip')
        ->where('reservation.isGroup', true)
        ->where('reservation.status.label', 'Confirmed')
        ->where('reservation.firstArrival', '2026-10-12')
        ->where('reservation.lastDeparture', '2026-10-17')
        ->where('reservation.unitCount', 2)
        ->where('reservation.roomNights', 7)
        ->where('reservation.stayTotal', '700.00')
        ->where('reservation.billingMode', 'each_pays')
        ->where('reservation.externalReference', 'OTA-1')
        ->where('reservation.createdBy', $reservation->creator->name)
        ->where('reservation.booker.name', 'Tan Wei Ming')
        ->where('reservation.booker.isVip', true)
        ->where('reservation.booker.id', $booker->id)
        ->where('reservation.booker.phone', '+60123456789')
        ->has('units', 3)
        ->where('units.2.status.label', 'Cancelled'));
});

test('a single unit reservation is not a group and a cancelled one still shows its dates', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property, ['status' => ReservationStatus::Cancelled]);
    pageUnit($reservation, ['status' => 'cancelled']);

    $this->actingAs($user)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('reservation.isGroup', false)
        ->where('reservation.isClosed', true)
        ->where('reservation.firstArrival', '2026-10-12')
        ->where('reservation.stayTotal', '0.00')
        ->where('reservation.title', $reservation->booker->full_name));
});

test('each tab sends only its own data', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation);
    UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id]);
    activity()->performedOn($reservation)->event('reservation.created')->withProperties(['units' => 1])->log('Reservation created');
    $tabs = [
        'reservations.show' => ['unit'],
        'reservations.guests' => ['guests'],
        'reservations.add-ons' => ['addOns'],
        'reservations.activity' => ['activity'],
    ];
    $all = ['unit', 'guests', 'addOns', 'activity'];

    foreach ($tabs as $route => $own) {
        $this->actingAs($user)->get(pageUrl($property, $reservation, $route))->assertInertia(function (Assert $page) use ($own, $all): void {
            foreach ($all as $prop) {
                in_array($prop, $own, true) ? $page->has($prop) : $page->missing($prop);
            }

            $page->has('reservation')->has('units');
        });
    }
});

test('the unit panel follows ?unit= and falls back to the first unit', function (?string $query, string $expected) {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $first = pageUnit($reservation);
    $second = pageUnit($reservation);
    $foreign = pageUnit(pageReservation($property));
    $ids = ['first' => $first->id, 'second' => $second->id, 'foreign' => $foreign->id];

    $url = pageUrl($property, $reservation, 'reservations.show', $query === null ? [] : ['unit' => $ids[$query] ?? $query]);

    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page->where('unit.id', $ids[$expected]));
})->with([
    'none' => [null, 'first'],
    'second' => ['second', 'second'],
    'another reservation\'s unit' => ['foreign', 'first'],
    'unknown id' => ['999999', 'first'],
    'not a number' => ['abc', 'first'],
]);

test('the unit panel shows the plan, guests, child ages, rooms, nights and primary guest', function () {
    [$user, $property] = pageOwner();
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe King']);
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    $other = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']);
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation, ['room_type_id' => $roomType->id, 'adults' => 2, 'children' => 2]);
    ReservationUnitChild::factory()->create(['reservation_unit_id' => $unit->id, 'age' => 9]);
    ReservationUnitChild::factory()->create(['reservation_unit_id' => $unit->id, 'age' => 4]);
    $primary = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Ahmad Faiz']);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $primary->id]);
    $nights = $unit->nights()->orderBy('date')->get();
    $nights[0]->update(['room_id' => $room->id]);
    $nights[1]->update(['room_id' => $room->id]);
    $nights[2]->update(['room_id' => $other->id, 'override_amount' => '90.00', 'amount' => '90.00']);

    $this->actingAs($user)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('unit.roomType', 'Deluxe King')
        ->where('unit.ratePlan', $unit->ratePlan->name)
        ->where('unit.nightCount', 3)
        ->where('unit.adults', 2)
        ->where('unit.childAges', [4, 9])
        ->where('unit.assigned', ['101', '102'])
        ->where('unit.allNightsAssigned', true)
        ->where('unit.primaryGuest', 'Ahmad Faiz')
        ->where('unit.primaryGuestId', $primary->id)
        ->where('unit.total', '290.00')
        ->where('unit.nights.0.date', '2026-10-12')
        ->where('unit.nights.0.assigned', '101')
        ->where('unit.nights.2.assigned', '102')
        ->where('unit.nights.2.isOverridden', true)
        ->where('unit.nights.0.isOverridden', false)
        ->where('units.0.label', 'Deluxe King · 101'));
});

test('a unit with no room shows a dash and no assigned rooms', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    pageUnit($reservation);

    $this->actingAs($user)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('unit.assigned', [])
        ->where('unit.allNightsAssigned', false)
        ->where('unit.primaryGuest', null)
        ->where('units.0.assigned', null)
        ->where('autoAssignRooms', false));
});

test('the price override reason and who set it come from the log of the unit that was added', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $overridden = pageUnit($reservation);
    $plain = pageUnit($reservation);
    $actor = User::factory()->create(['name' => 'Aina']);
    activity()->performedOn($reservation)->causedBy($actor)->event('reservation.unit_added')->withProperties([
        'reservation_unit_id' => $overridden->id,
        'override_total' => '240.00',
        'override_reason' => 'Corporate rate',
    ])->log('Unit added to reservation');
    activity()->performedOn($reservation)->causedBy($actor)->event('reservation.unit_added')->withProperties(['reservation_unit_id' => $plain->id])->log('Unit added to reservation');

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.show', ['unit' => $overridden->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.priceOverrideReason', 'Corporate rate')->where('unit.priceOverrideBy', 'Aina'));
    $this->get(pageUrl($property, $reservation, 'reservations.show', ['unit' => $plain->id]))
        ->assertInertia(fn (Assert $page) => $page->where('unit.priceOverrideReason', null)->where('unit.priceOverrideBy', null));
});

test('the guest rows show the booker, unit guests with roles, and units without a guest', function () {
    [$user, $property] = pageOwner();
    $booker = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60111111111']);
    $reservation = pageReservation($property, ['booker_guest_id' => $booker->id]);
    $first = pageUnit($reservation);
    $second = pageUnit($reservation);
    pageUnit($reservation);
    $cancelled = pageUnit($reservation, ['status' => 'cancelled']);
    $other = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Ahmad Faiz']);
    UnitGuest::factory()->create(['reservation_unit_id' => $first->id, 'guest_id' => $other->id]);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $first->id, 'guest_id' => $booker->id]);

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.guests'))->assertInertia(fn (Assert $page) => $page
        ->has('guests', 5)
        ->where('guests.0.name', 'Tan Wei Ming')
        ->where('guests.0.roles', ['Booker', 'Primary'])
        ->where('guests.0.unitId', $first->id)
        ->where('guests.0.phone', '+60111111111')
        ->where('guests.1.name', 'Ahmad Faiz')
        ->where('guests.1.roles', [])
        ->where('guests.2.name', null)
        ->where('guests.2.unitId', $second->id)
        ->where('guests.4.unitId', $cancelled->id)
        ->where('guests.4.unitStatus.label', 'Cancelled'));
});

test('a booker who does not stay is listed first with no unit', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id]);

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.guests'))->assertInertia(fn (Assert $page) => $page
        ->has('guests', 2)
        ->where('guests.0.name', $reservation->booker->full_name)
        ->where('guests.0.unitId', null)
        ->where('guests.0.roles', ['Booker'])
        ->where('guests.1.roles', ['Primary']));
});

test('the add-on rows are grouped by unit and ordered by service date', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $first = pageUnit($reservation);
    $second = pageUnit($reservation);
    $late = UnitAddOn::factory()->create(['reservation_unit_id' => $second->id, 'quantity' => 3, 'unit_amount' => '12.50', 'service_date' => '2026-10-14']);
    $early = UnitAddOn::factory()->create(['reservation_unit_id' => $first->id, 'quantity' => 2, 'unit_amount' => '50.00', 'service_date' => '2026-10-13']);
    $earlier = UnitAddOn::factory()->create(['reservation_unit_id' => $first->id, 'service_date' => '2026-10-12']);
    UnitAddOn::factory()->create(['reservation_unit_id' => pageUnit(pageReservation($property))->id]);

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.add-ons'))->assertInertia(fn (Assert $page) => $page
        ->has('addOns', 3)
        ->where('addOns.0.id', $earlier->id)
        ->where('addOns.1.id', $early->id)
        ->where('addOns.1.total', '100.00')
        ->where('addOns.1.chargeItem', $early->chargeItem->name)
        ->where('addOns.2.id', $late->id)
        ->where('addOns.2.total', '37.50')
        ->where('addOns.2.unitId', $second->id)
        ->where('addOns.2.unitLabel', $second->roomType->name.' · Not assigned'));
});

test('the activity tab lists entries newest first, 25 to a page, in readable words', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation);
    $roomType = $unit->roomType;
    $actor = User::factory()->create(['name' => 'Aina']);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Ahmad Faiz']);

    foreach (range(1, 27) as $number) {
        activity()->performedOn($reservation)->event('reservation.created')->withProperties(['units' => $number])->log('Reservation created');
    }

    activity()->performedOn($reservation)->causedBy($actor)->event('reservation.unit_added')->withProperties([
        'reservation_unit_id' => $unit->id,
        'room_type_id' => $roomType->id,
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-15',
        'override_total' => '240.00',
        'override_reason' => 'Corporate rate',
    ])->log('Unit added to reservation');
    activity()->performedOn($reservation)->causedBy($actor)->event('reservation.group_updated')->withProperties([
        'old' => ['group_name' => null, 'billing_mode' => 'master_pays'],
        'new' => ['group_name' => 'Tan trip', 'billing_mode' => 'each_pays'],
    ])->log('Reservation group updated');
    activity()->performedOn($reservation)->causedBy($actor)->event('reservation.guest_attached')->withProperties(['guest_id' => $guest->id])->log('Guest attached to unit');
    activity()->performedOn(pageReservation($property))->event('reservation.created')->log('Another reservation');

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.activity'))->assertInertia(fn (Assert $page) => $page
        ->has('activity.data', 25)
        ->where('activity.meta.total', 30)
        ->where('activity.data.0.text', 'Ahmad Faiz added to a unit')
        ->where('activity.data.0.causerName', 'Aina')
        ->where('activity.data.0.kind', 'reservation.guest_attached')
        ->where('activity.data.1.text', 'Group updated: group name none → Tan trip, billing Master folio pays → Each unit pays')
        ->where('activity.data.2.text', "Unit added: {$roomType->name}, 2026-10-12 to 2026-10-15")
        ->where('activity.data.2.reason', 'Price override to 240.00: Corporate rate')
        ->where('activity.data.3.text', 'Reservation created with 27 units')
        ->where('activity.data.3.causerName', null)
        ->where('activity.meta.next_page_url', fn (string $url): bool => str_contains($url, 'page=2')));

    $this->get(pageUrl($property, $reservation, 'reservations.activity', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('activity.data', 5)
        ->where('activity.data.4.text', 'Reservation created with 1 units'));
});

test('an entry the page does not know is shown by its description', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    pageUnit($reservation);
    activity()->performedOn($reservation)->event('reservation.something_new')->log('Something new happened');

    $this->actingAs($user)->get(pageUrl($property, $reservation, 'reservations.activity'))
        ->assertInertia(fn (Assert $page) => $page->where('activity.data.0.text', 'Something new happened'));
});

test('staff without guests.view see names and flags but no contact details or guest links', function () {
    [$staff, $property] = pageStaffWith(['reservations.view']);
    $booker = Guest::factory()->vip()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789', 'email' => 'tan@example.com']);
    $reservation = pageReservation($property, ['booker_guest_id' => $booker->id]);
    $unit = pageUnit($reservation);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $booker->id]);

    $this->actingAs($staff)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('canViewGuest', false)
        ->where('reservation.booker.name', 'Tan Wei Ming')
        ->where('reservation.booker.isVip', true)
        ->where('reservation.booker.id', null)
        ->where('reservation.booker.phone', null)
        ->where('reservation.booker.email', null)
        ->where('unit.primaryGuest', 'Tan Wei Ming')
        ->where('unit.primaryGuestId', null));
    $this->get(pageUrl($property, $reservation, 'reservations.guests'))->assertInertia(fn (Assert $page) => $page
        ->where('guests.0.name', 'Tan Wei Ming')
        ->where('guests.0.guestId', null)
        ->where('guests.0.phone', null));
    $this->get(route('reservations.index', $property->code))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.bookerName', 'Tan Wei Ming')
        ->where('reservations.data.0.bookerId', null));
});

test('staff with guests.view get the contact details and guest links', function () {
    [$staff, $property] = pageStaffWith(['reservations.view', 'guests.view']);
    $booker = Guest::factory()->create(['property_id' => $property->id, 'phone' => '+60123456789', 'email' => 'tan@example.com']);
    $reservation = pageReservation($property, ['booker_guest_id' => $booker->id]);
    pageUnit($reservation);

    $this->actingAs($staff)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('canViewGuest', true)
        ->where('reservation.booker.id', $booker->id)
        ->where('reservation.booker.phone', '+60123456789')
        ->where('reservation.booker.email', 'tan@example.com'));
    $this->get(route('reservations.index', $property->code))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.bookerId', $booker->id));
});

test('the page needs a constant number of queries however many units it has', function () {
    [$user, $property] = pageOwner();
    $roomType = RoomType::factory()->for($property)->create();

    $queries = function (int $units, string $route) use ($user, $property, $roomType): int {
        $reservation = pageReservation($property);

        foreach (range(1, $units) as $ignored) {
            $unit = pageUnit($reservation, ['room_type_id' => $roomType->id]);
            UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => Room::factory()->for($property)->create(['room_type_id' => $roomType->id])->id]);
            UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id]);
            UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id]);
            activity()->performedOn($reservation)->event('reservation.add_on_added')->withProperties(['charge_item_id' => 1])->log('x');
        }

        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->get(pageUrl($property, $reservation, $route))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $count;
    };

    test()->actingAs($user)->get(pageUrl($property, pageReservation($property)))->assertOk();

    foreach (['reservations.show', 'reservations.guests', 'reservations.add-ons', 'reservations.activity'] as $route) {
        $few = $queries(2, $route);
        $many = $queries(7, $route);

        expect($many)->toBe($few, "queries on {$route}")->and($many)->toBeLessThanOrEqual(25);
    }
});

test('the page carries the notes of the booking', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property, ['notes' => 'Needs a cot.']);
    pageUnit($reservation);

    test()->actingAs($user)
        ->get(pageUrl($property, $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('reservation.notes', 'Needs a cot.'));

    $plain = pageReservation($property);
    pageUnit($plain);

    test()->get(pageUrl($property, $plain))->assertInertia(fn (Assert $page) => $page->where('reservation.notes', null));
});

test('an add-on says where it is billed: posted folio, routed folio or nothing', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation);
    $unitFolio = Folio::factory()->forUnit($unit)->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    $line = FolioLine::factory()->create(['folio_id' => $unitFolio->id, 'posting_date' => '2026-10-10']);
    $posted = UnitAddOn::factory()->posted($line)->create(['reservation_unit_id' => $unit->id, 'service_date' => '2026-10-10']);
    $plain = UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'service_date' => '2026-10-11']);

    $url = pageUrl($property, $reservation, 'reservations.add-ons');
    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('addOns.0.id', $posted->id)
        ->where('addOns.0.posted', true)
        ->where('addOns.0.folioId', $unitFolio->id)
        ->where('addOns.0.postedOn', '2026-10-10')
        ->where('addOns.0.folioLabel', fn ($label) => str_starts_with($label, 'Unit '))
        ->where('addOns.0.routedTo', null)
        ->where('addOns.1.id', $plain->id)
        ->where('addOns.1.posted', false)
        ->where('addOns.1.folioId', null)
        ->where('addOns.1.routedTo', null));

    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $reservation->id]);

    $this->actingAs($user)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('addOns.0.routedTo', null)
        ->where('addOns.1.routedTo', fn ($label) => str_starts_with($label, 'Master')));
});

test('the add-ons tab reads in the same number of queries for any number of add-ons', function () {
    [$user, $property] = pageOwner();
    $reservation = pageReservation($property);
    $unit = pageUnit($reservation);
    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    FolioRoutingRule::factory()->toFolio($master)->create(['reservation_id' => $reservation->id]);
    $url = pageUrl($property, $reservation, 'reservations.add-ons');
    $count = function () use ($user, $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get($url)->assertOk();

        return count(DB::getQueryLog());
    };

    UnitAddOn::factory()->count(2)->create(['reservation_unit_id' => $unit->id]);
    $this->actingAs($user)->get($url)->assertOk();
    $few = $count();
    UnitAddOn::factory()->count(6)->create(['reservation_unit_id' => $unit->id]);
    $many = $count();

    expect($many)->toBe($few);
});

test('Check out all is offered for two or more units when one is checked in or holds money', function () {
    [$user, $property] = pageOwner();
    $single = pageReservation($property);
    ReservationUnit::factory()->for($single)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-09', 'departure_date' => '2026-10-12']);
    $quiet = pageReservation($property);
    pageUnit($quiet);
    pageUnit($quiet);
    $group = pageReservation($property);
    ReservationUnit::factory()->for($group)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-09', 'departure_date' => '2026-10-12']);
    pageUnit($group);

    foreach ([[$single, false], [$quiet, false], [$group, true]] as [$reservation, $expected]) {
        $this->actingAs($user)->get(pageUrl($property, $reservation))->assertInertia(fn (Assert $page) => $page->where('canCheckOutAll', $expected));
    }

    [$staff] = pageStaffWith(['reservations.view']);
    $this->actingAs($staff)->get(pageUrl($staff->property, pageReservation($staff->property)))->assertInertia(fn (Assert $page) => $page->where('canCheckOutAll', false));
});
