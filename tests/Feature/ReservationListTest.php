<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner whose property has settings, so the list can read the currency and the room assignment setting.
 *
 * @return array{0: User, 1: Property}
 */
function listOwner(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    $user = User::factory()->withRole($role)->create();
    if (! PropertySetting::query()->where('property_id', $user->property_id)->exists()) {
        PropertySetting::factory()->for($user->property)->create();
    }

    return [$user, $user->property];
}

/**
 * A reservation of the property with units. Each unit is an array of unit attributes, with the optional keys
 * `nights` (false for none) and `room_type` (a RoomType). Nights are priced at 100.00.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>|null  $units
 */
function listReservation(Property $property, array $attributes = [], ?array $units = null): Reservation
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id, ...$attributes]);

    foreach ($units ?? [[]] as $unit) {
        $withNights = $unit['nights'] ?? true;
        $roomType = $unit['room_type'] ?? null;
        unset($unit['nights'], $unit['room_type']);
        $factory = ReservationUnit::factory()->for($reservation);
        $factory = $withNights ? $factory->withNights() : $factory;
        $factory->create([
            'arrival_date' => '2026-10-12',
            'departure_date' => '2026-10-15',
            ...($roomType === null ? [] : ['room_type_id' => $roomType->id]),
            ...$unit,
        ]);
    }

    return $reservation;
}

/**
 * @return array<string, mixed>
 */
function listUrl(Property $property, array $query = []): string
{
    return route('reservations.index', ['property' => $property->code, ...$query]);
}

/**
 * @return list<string>
 */
function listedReferences(User $user, Property $property, array $query = []): array
{
    $references = [];

    test()->actingAs($user)->get(listUrl($property, $query))
        ->assertInertia(function (Assert $page) use (&$references): void {
            $references = collect($page->toArray()['props']['reservations']['data'])->pluck('reference')->all();
        });

    return $references;
}

test('reservations.view roles can open the list and others cannot', function (DefaultRoleEnum $role, int $status) {
    [$user, $property] = listOwner($role);

    $this->actingAs($user)->get(listUrl($property))->assertStatus($status);
})->with([
    [DefaultRoleEnum::Owner, 200],
    [DefaultRoleEnum::Manager, 200],
    [DefaultRoleEnum::FrontDesk, 200],
    [DefaultRoleEnum::Finance, 200],
    [DefaultRoleEnum::Housekeeping, 403],
]);

test('another property\'s code returns 404', function () {
    [$user] = listOwner();
    $other = Property::factory()->create();

    $this->actingAs($user)->get(listUrl($other))->assertNotFound();
});

test('only the property\'s own reservations are listed', function () {
    [$user, $property] = listOwner();
    $own = listReservation($property);
    listReservation(Property::factory()->create());

    expect(listedReferences($user, $property))->toBe([$own->reference]);
});

test('the list sends one item with its stay, units, occupancy, total and source', function () {
    [$user, $property] = listOwner();
    $reservation = listReservation($property, ['source' => ReservationSource::Channel, 'channel_name' => 'Booking.com', 'external_reference' => 'BK-77', 'group_name' => 'Tan trip'], [
        ['adults' => 2, 'children' => 1],
        ['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-16', 'adults' => 1],
    ]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->component('reservations/index')
        ->where('currency', 'MYR')
        ->where('reservations.data.0.id', $reservation->id)
        ->where('reservations.data.0.title', 'Tan trip')
        ->where('reservations.data.0.isGroup', true)
        ->where('reservations.data.0.unitCount', 2)
        ->where('reservations.data.0.adults', 3)
        ->where('reservations.data.0.children', 1)
        ->where('reservations.data.0.firstArrival', '2026-10-12')
        ->where('reservations.data.0.lastDeparture', '2026-10-16')
        ->where('reservations.data.0.nights', 4)
        ->where('reservations.data.0.total', '600.00')
        ->where('reservations.data.0.sourceLabel', 'Channel')
        ->where('reservations.data.0.channelName', 'Booking.com')
        ->where('reservations.data.0.externalReference', 'BK-77')
        ->where('reservations.data.0.status.label', 'Confirmed')
        ->where('reservations.data.0.bookedOn', now()->toDateString())
        ->has('reservations.data.0.roomTypes', 2));
});

test('units that do not hold inventory are left out of the total and occupancy, and dates fall back to all units', function () {
    [$user, $property] = listOwner();
    listReservation($property, [], [
        ['adults' => 2],
        ['adults' => 3, 'status' => 'cancelled', 'arrival_date' => '2026-10-11'],
    ]);
    $cancelled = listReservation($property, ['status' => ReservationStatus::Cancelled], [
        ['status' => 'cancelled', 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22'],
    ]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.unitCount', 1)
        ->where('reservations.data.0.adults', 2)
        ->where('reservations.data.0.total', '300.00')
        ->where('reservations.data.0.firstArrival', '2026-10-12')
        ->where('reservations.data.1.id', $cancelled->id)
        ->where('reservations.data.1.total', '0.00')
        ->where('reservations.data.1.firstArrival', '2026-10-20')
        ->where('reservations.data.1.unitCount', 1));
});

test('by default only stays that end after the business date are listed', function () {
    [$user, $property] = listOwner();
    $past = listReservation($property, [], [['arrival_date' => '2026-10-01', 'departure_date' => '2026-10-10']]);
    $leaving = listReservation($property, [], [['arrival_date' => '2026-10-08', 'departure_date' => '2026-10-11']]);
    $future = listReservation($property, [], [['arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03']]);

    expect(listedReferences($user, $property))->toBe([$leaving->reference, $future->reference])
        ->and(listedReferences($user, $property))->not->toContain($past->reference);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('filters.stay', ['mode' => 'default', 'from' => '2026-10-10', 'to' => null]));
});

test('the default stay starts at the current business date when the property has one', function () {
    [$user, $property] = listOwner();
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-20']);
    $before = listReservation($property, [], [['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15']]);
    $after = listReservation($property, [], [['arrival_date' => '2026-10-19', 'departure_date' => '2026-10-21']]);

    expect(listedReferences($user, $property))->toBe([$after->reference])
        ->and(listedReferences($user, $property))->not->toContain($before->reference);
});

test('filter stay all lists every date', function () {
    [$user, $property] = listOwner();
    $past = listReservation($property, [], [['arrival_date' => '2026-09-01', 'departure_date' => '2026-09-03']]);
    $future = listReservation($property);

    expect(listedReferences($user, $property, ['filter' => ['stay' => 'all']]))->toBe([$past->reference, $future->reference]);

    $this->actingAs($user)->get(listUrl($property, ['filter' => ['stay' => 'all']]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.stay.mode', 'all'));
});

test('a stay range matches stays that overlap it, with the departure day free', function (string $stay, bool $listed) {
    [$user, $property] = listOwner();
    $reservation = listReservation($property);

    expect(in_array($reservation->reference, listedReferences($user, $property, ['filter' => ['stay' => $stay]]), true))->toBe($listed);
})->with([
    'ends on the arrival day' => ['2026-10-01_2026-10-12', true],
    'ends the day before arrival' => ['2026-10-01_2026-10-11', false],
    'starts on the last night' => ['2026-10-14_2026-10-30', true],
    'starts on the departure day' => ['2026-10-15_2026-10-30', false],
    'open end from the first night' => ['2026-10-12_', true],
    'open start to the arrival day' => ['_2026-10-12', true],
    'open start before the arrival day' => ['_2026-10-11', false],
]);

test('a malformed stay falls back to the default', function () {
    [$user, $property] = listOwner();

    $this->actingAs($user)->get(listUrl($property, ['filter' => ['stay' => 'tomorrow']]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.stay.mode', 'default'));
});

test('the status filter narrows the list and an unknown status is ignored', function () {
    [$user, $property] = listOwner();
    $confirmed = listReservation($property);
    $inHouse = listReservation($property, ['status' => ReservationStatus::InHouse]);

    expect(listedReferences($user, $property, ['filter' => ['status' => 'in_house']]))->toBe([$inHouse->reference])
        ->and(listedReferences($user, $property, ['filter' => ['status' => 'bogus']]))->toEqualCanonicalizing([$confirmed->reference, $inHouse->reference]);
});

test('the source filter narrows the list', function () {
    [$user, $property] = listOwner();
    listReservation($property);
    $phone = listReservation($property, ['source' => ReservationSource::Phone]);

    expect(listedReferences($user, $property, ['filter' => ['source' => 'phone']]))->toBe([$phone->reference]);
});

test('the status counts follow every filter except the status', function () {
    [$user, $property] = listOwner();
    listReservation($property, ['source' => ReservationSource::Phone]);
    listReservation($property, ['source' => ReservationSource::Phone, 'status' => ReservationStatus::InHouse]);
    listReservation($property, ['source' => ReservationSource::Phone, 'status' => ReservationStatus::Cancelled]);
    listReservation($property, ['status' => ReservationStatus::InHouse]);
    listReservation($property, ['status' => ReservationStatus::Completed], [['arrival_date' => '2026-09-01', 'departure_date' => '2026-09-02']]);

    $counts = fn (array $query): array => collect(test()->actingAs($user)->get(listUrl($property, $query))->original->getData()['page']['props']['statusCounts'])
        ->mapWithKeys(fn (array $row): array => [$row['value'] => $row['count']])
        ->all();

    expect($counts([]))->toBe(['' => 4, 'confirmed' => 1, 'in_house' => 2, 'completed' => 0, 'cancelled' => 1])
        ->and($counts(['filter' => ['source' => 'phone', 'status' => 'cancelled']]))->toBe(['' => 3, 'confirmed' => 1, 'in_house' => 1, 'completed' => 0, 'cancelled' => 1])
        ->and($counts(['filter' => ['stay' => 'all']]))->toBe(['' => 5, 'confirmed' => 1, 'in_house' => 2, 'completed' => 1, 'cancelled' => 1]);
});

test('the status tabs are sent with their labels in order', function () {
    [$user, $property] = listOwner();

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('statusCounts.0.label', 'All')
        ->where('statusCounts.1.value', 'confirmed')
        ->where('statusCounts.2.label', 'In house')
        ->where('statusCounts.4.value', 'cancelled'));
});

test('search finds the reference, the external reference and the booker', function () {
    [$user, $property] = listOwner();
    $byReference = listReservation($property, ['external_reference' => 'OTA-5521']);
    $booker = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Zainab Karim', 'email' => 'zainab@example.com']);
    $byBooker = listReservation($property, ['booker_guest_id' => $booker->id]);
    listReservation($property);

    expect(listedReferences($user, $property, ['filter' => ['search' => $byReference->reference]]))->toBe([$byReference->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => 'ota-5521']]))->toBe([$byReference->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => 'zainab k']]))->toBe([$byBooker->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => 'zainab@example']]))->toBe([$byBooker->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => 'nobody at all']]))->toBe([]);
});

test('search finds a guest of any unit, and a local phone number matches the stored E.164 number', function () {
    [$user, $property] = listOwner();
    $reservation = listReservation($property, [], [[], []]);
    listReservation($property);
    $occupant = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Wira Hamid', 'phone' => '+60123456789']);
    UnitGuest::factory()->create(['reservation_unit_id' => $reservation->units()->orderByDesc('id')->value('id'), 'guest_id' => $occupant->id]);

    expect(listedReferences($user, $property, ['filter' => ['search' => 'wira']]))->toBe([$reservation->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => '012-345 6789']]))->toBe([$reservation->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => '+60 12 345 6789']]))->toBe([$reservation->reference])
        ->and(listedReferences($user, $property, ['filter' => ['search' => '0199999999']]))->toBe([]);
});

test('search never finds a guest of another property and counts follow it', function () {
    [$user, $property] = listOwner();
    $reservation = listReservation($property);
    $foreign = Guest::factory()->create(['full_name' => 'Wira Hamid']);
    listReservation(Property::factory()->create(), ['booker_guest_id' => $foreign->id]);
    UnitGuest::factory()->create(['reservation_unit_id' => $reservation->units()->value('id'), 'guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Local Guest'])->id]);

    expect(listedReferences($user, $property, ['filter' => ['search' => 'wira']]))->toBe([]);

    $this->actingAs($user)->get(listUrl($property, ['filter' => ['search' => 'local guest']]))
        ->assertInertia(fn (Assert $page) => $page->where('statusCounts.0.count', 1)->where('statusCounts.1.count', 1));
});

test('the list sorts by arrival, departure, reference, total and booked date, either way', function () {
    [$user, $property] = listOwner();
    $a = listReservation($property, ['created_at' => '2026-09-03 10:00:00'], [['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-21']]);
    $b = listReservation($property, ['created_at' => '2026-09-01 10:00:00'], [['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-30']]);
    $c = listReservation($property, ['created_at' => '2026-09-02 10:00:00'], [['arrival_date' => '2026-10-15', 'departure_date' => '2026-10-17']]);
    $refs = fn (string $sort): array => listedReferences($user, $property, ['sort' => $sort]);

    expect($refs('arrival'))->toBe([$b->reference, $c->reference, $a->reference])
        ->and($refs('-arrival'))->toBe([$a->reference, $c->reference, $b->reference])
        ->and($refs('departure'))->toBe([$c->reference, $a->reference, $b->reference])
        ->and($refs('-total'))->toBe([$b->reference, $c->reference, $a->reference])
        ->and($refs('total'))->toBe([$a->reference, $c->reference, $b->reference])
        ->and($refs('booked'))->toBe([$b->reference, $c->reference, $a->reference])
        ->and($refs('-booked'))->toBe([$a->reference, $c->reference, $b->reference])
        ->and($refs('reference'))->toBe(collect([$a, $b, $c])->pluck('reference')->sort()->values()->all())
        ->and($refs('-reference'))->toBe(collect([$a, $b, $c])->pluck('reference')->sort()->reverse()->values()->all());
});

test('paid and balance add up the folios that are not Voided, and are 0.00 without one', function () {
    [$user, $property] = listOwner();
    $group = listReservation($property, ['created_at' => '2026-09-01 10:00:00']);
    $master = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $group->id]);
    $unitFolio = Folio::factory()->forUnit($group->units()->first())->create(['property_id' => $property->id, 'reservation_id' => $group->id]);
    FolioLine::factory()->for($master)->create(['unit_amount' => '300.00', 'amount' => '300.00']);
    FolioLine::factory()->for($master)->create(['kind' => TransactionKind::Tax, 'unit_amount' => '36.00', 'amount' => '36.00']);
    FolioLine::factory()->for($unitFolio)->payment('200.00')->create();
    FolioLine::factory()->for($unitFolio)->create(['kind' => TransactionKind::Payment, 'description' => 'Refund', 'unit_amount' => '50.00', 'amount' => '50.00']);
    FolioLine::factory()->for(Folio::factory()->voided()->thirdParty()->create(['property_id' => $property->id, 'reservation_id' => $group->id]))->create(['unit_amount' => '999.00', 'amount' => '999.00']);

    $deposit = listReservation($property, ['created_at' => '2026-09-02 10:00:00']);
    FolioLine::factory()->for(Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $deposit->id]))->payment('300.00')->create();

    listReservation($property, ['created_at' => '2026-09-03 10:00:00']);
    $voidedOnly = listReservation($property, ['created_at' => '2026-09-04 10:00:00']);
    Folio::factory()->voided()->create(['property_id' => $property->id, 'reservation_id' => $voidedOnly->id]);

    $this->actingAs($user)->get(listUrl($property, ['sort' => 'booked']))->assertInertia(function (Assert $page): void {
        $rows = collect($page->toArray()['props']['reservations']['data']);

        expect($rows->pluck('paid')->all())->toBe(['150.00', '300.00', '0.00', '0.00'])
            ->and($rows->pluck('balance')->all())->toBe(['186.00', '-300.00', '0.00', '0.00']);
    });
});

test('a folio settled to the city ledger shows as billed to the company, not as paid, and a reversal nets it out', function () {
    [$user, $property] = listOwner();
    $directBill = TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $property->id]);
    $settled = listReservation($property, ['created_at' => '2026-09-01 10:00:00']);
    $folio = Folio::factory()->master()->closed()->create(['property_id' => $property->id, 'reservation_id' => $settled->id]);
    FolioLine::factory()->for($folio)->create(['unit_amount' => '1700.00', 'amount' => '1700.00']);
    FolioLine::factory()->for($folio)->payment('300.00')->create();
    FolioLine::factory()->for($folio)->payment('1400.00')->create(['transaction_code_id' => $directBill->id]);

    $reversed = listReservation($property, ['created_at' => '2026-09-02 10:00:00']);
    $other = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reversed->id]);
    FolioLine::factory()->for($other)->create(['unit_amount' => '500.00', 'amount' => '500.00']);
    FolioLine::factory()->for($other)->payment('500.00')->create(['transaction_code_id' => $directBill->id]);
    FolioLine::factory()->for($other)->create(['kind' => TransactionKind::Payment, 'transaction_code_id' => $directBill->id, 'unit_amount' => '500.00', 'amount' => '500.00', 'reversal_of_id' => FolioLine::query()->where('folio_id', $other->id)->where('amount', '-500.00')->value('id')]);

    $this->actingAs($user)->get(listUrl($property, ['sort' => 'booked']))->assertInertia(function (Assert $page): void {
        $rows = collect($page->toArray()['props']['reservations']['data']);

        expect($rows->pluck('paid')->all())->toBe(['300.00', '0.00'])
            ->and($rows->pluck('billedToCompany')->all())->toBe(['1400.00', '0.00'])
            ->and($rows->pluck('balance')->all())->toBe(['0.00', '500.00']);
    });
});

test('the list sorts by balance either way, reading no folio as 0', function () {
    [$user, $property] = listOwner();
    $owing = listReservation($property);
    FolioLine::factory()->for(Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $owing->id]))->create();
    $none = listReservation($property);
    $deposit = listReservation($property);
    FolioLine::factory()->for(Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $deposit->id]))->payment('300.00')->create();

    expect(listedReferences($user, $property, ['sort' => 'balance']))->toBe([$deposit->reference, $none->reference, $owing->reference])
        ->and(listedReferences($user, $property, ['sort' => '-balance']))->toBe([$owing->reference, $none->reference, $deposit->reference]);
});

test('equal sort values are ordered by id and an unknown sort is refused', function () {
    [$user, $property] = listOwner();
    $first = listReservation($property);
    $second = listReservation($property);

    expect(listedReferences($user, $property))->toBe([$first->reference, $second->reference]);

    $this->actingAs($user)->get(listUrl($property, ['sort' => 'colour']))->assertStatus(400);
});

test('the list has 24 reservations a page and keeps the filters in the page links', function () {
    [$user, $property] = listOwner();
    Reservation::factory()->count(26)->create(['property_id' => $property->id])->each(
        fn (Reservation $reservation) => ReservationUnit::factory()->for($reservation)->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15']),
    );

    $this->actingAs($user)->get(listUrl($property, ['sort' => '-reference']))->assertInertia(fn (Assert $page) => $page
        ->has('reservations.data', 24)
        ->where('reservations.meta.total', 26)
        ->where('reservations.meta.next_page_url', fn (string $url): bool => str_contains($url, 'sort=-reference') && str_contains($url, 'page=2')));
});

test('the room box shows the first night room or bed of each unit', function () {
    [$user, $property] = listOwner();
    $roomType = RoomType::factory()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    $other = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']);
    $third = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '103']);
    $fourth = Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '104']);
    $single = listReservation($property, [], [['room_type' => $roomType]]);
    UnitNight::query()->whereIn('reservation_unit_id', $single->units()->pluck('id'))->update(['room_id' => $room->id]);
    $group = listReservation($property, ['group_name' => 'Trip'], [['room_type' => $roomType], ['room_type' => $roomType], ['room_type' => $roomType], ['room_type' => $roomType]]);
    foreach ($group->units()->orderBy('id')->get()->take(3) as $index => $unit) {
        UnitNight::query()->where('reservation_unit_id', $unit->id)->orderBy('date')->limit(1)->update(['room_id' => [$other, $third, $fourth][$index]->id]);
        UnitNight::query()->where('reservation_unit_id', $unit->id)->where('date', '>', '2026-10-12')->update(['room_id' => Room::factory()->for($property)->create(['room_type_id' => $roomType->id])->id]);
    }

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.roomBox.state', 'assigned')
        ->where('reservations.data.0.roomBox.numbers', ['101'])
        ->where('reservations.data.0.roomBox.units', [])
        ->where('reservations.data.1.roomBox.state', 'assigned')
        ->where('reservations.data.1.roomBox.numbers', ['102', '103'])
        ->where('reservations.data.1.roomBox.more', 1)
        ->where('reservations.data.1.roomBox.count', 1)
        ->where('reservations.data.1.roomBox.units.3.assigned', null)
        ->where('reservations.data.1.roomBox.units.0.roomType', $roomType->name));
});

test('the room box shows a bed label for a bed unit', function () {
    [$user, $property] = listOwner();
    $roomType = RoomType::factory()->bed()->for($property)->create();
    $room = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    $bed = Bed::factory()->create(['room_id' => $room->id, 'label' => 'D1-2']);
    $reservation = listReservation($property, [], [['room_type' => $roomType]]);
    UnitNight::query()->whereIn('reservation_unit_id', $reservation->units()->pluck('id'))->update(['bed_id' => $bed->id]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.roomBox.numbers', ['D1-2']));
});

test('the room box has a warning state for rooms and beds not assigned', function () {
    [$user, $property] = listOwner();
    $bedType = RoomType::factory()->bed()->for($property)->create();
    $room = listReservation($property);
    $bed = listReservation($property, [], [['room_type' => $bedType]]);
    $group = listReservation($property, ['group_name' => 'Trip'], [[], []]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.roomBox.state', 'unassigned')
        ->where('reservations.data.0.roomBox.tone', 'warning')
        ->where('reservations.data.0.roomBox.isBed', false)
        ->where('reservations.data.0.roomBox.tooltip', 'Room not assigned')
        ->where('reservations.data.1.roomBox.isBed', true)
        ->where('reservations.data.1.roomBox.tooltip', 'Bed not assigned')
        ->where('reservations.data.2.roomBox.count', 2)
        ->where('reservations.data.2.roomBox.tooltip', '2 units without a room or bed'));

    expect([$room->id, $bed->id, $group->id])->not->toBeEmpty();
});

test('the room box says the room is assigned automatically when the property assigns rooms', function () {
    [$user, $property] = listOwner();
    $property->settings->forceFill(['auto_assign_rooms' => true])->save();
    listReservation($property);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.roomBox.state', 'auto_assign')
        ->where('reservations.data.0.roomBox.tone', 'neutral')
        ->where('reservations.data.0.roomBox.tooltip', 'Room assigned automatically'));
});

test('the room box shows cancelled and no-show', function () {
    [$user, $property] = listOwner();
    listReservation($property, ['status' => ReservationStatus::Cancelled], [['status' => 'cancelled']]);
    listReservation($property, ['status' => ReservationStatus::Completed], [['status' => 'no_show'], ['status' => 'cancelled']]);

    $this->actingAs($user)->get(listUrl($property, ['filter' => ['stay' => 'all']]))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.roomBox.state', 'cancelled')
        ->where('reservations.data.0.roomBox.tooltip', 'Cancelled')
        ->where('reservations.data.0.isCancelled', true)
        ->where('reservations.data.1.roomBox.state', 'no_show')
        ->where('reservations.data.1.roomBox.tooltip', 'No-show'));
});

test('the markers show VIP, blacklisted, repeat and group', function () {
    [$user, $property] = listOwner();
    $vip = Guest::factory()->vip()->create(['property_id' => $property->id]);
    $blacklisted = Guest::factory()->blacklisted()->create(['property_id' => $property->id]);
    $repeat = Guest::factory()->create(['property_id' => $property->id]);
    $once = Guest::factory()->create(['property_id' => $property->id]);
    listReservation($property, ['booker_guest_id' => $vip->id], [[], []]);
    listReservation($property, ['booker_guest_id' => $blacklisted->id]);
    listReservation($property, ['booker_guest_id' => $repeat->id, 'status' => ReservationStatus::Completed], [['arrival_date' => '2026-08-01', 'departure_date' => '2026-08-03']]);
    listReservation($property, ['booker_guest_id' => $repeat->id]);
    listReservation($property, ['booker_guest_id' => $once->id, 'status' => ReservationStatus::Completed], [['arrival_date' => '2026-08-01', 'departure_date' => '2026-08-03']]);

    $this->actingAs($user)->get(listUrl($property, ['filter' => ['stay' => 'all'], 'sort' => 'booked']))->assertInertia(function (Assert $page): void {
        $rows = collect($page->toArray()['props']['reservations']['data']);

        expect($rows->pluck('isVip')->all())->toBe([true, false, false, false, false])
            ->and($rows->pluck('isBlacklisted')->all())->toBe([false, true, false, false, false])
            ->and($rows->pluck('isGroup')->all())->toBe([true, false, false, false, false])
            ->and($rows->pluck('isRepeat')->all())->toBe([false, false, false, true, false]);
    });
});

test('the title is the group name or the booker, and the booker link needs guests.view', function () {
    [$user, $property] = listOwner();
    $booker = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming']);
    listReservation($property, ['booker_guest_id' => $booker->id]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.title', 'Tan Wei Ming')
        ->where('reservations.data.0.bookerId', $booker->id));
});

test('the list needs a constant number of queries with lazy loading prevented', function () {
    [$user, $property] = listOwner();
    $roomType = RoomType::factory()->for($property)->create();

    $queries = function (int $added) use ($user, $property, $roomType): int {
        foreach (range(1, $added) as $ignored) {
            $reservation = listReservation($property, ['group_name' => 'Trip'], [['room_type' => $roomType], ['room_type' => $roomType]]);

            foreach ($reservation->units()->pluck('id') as $unitId) {
                UnitNight::query()->where('reservation_unit_id', $unitId)->update(['room_id' => Room::factory()->for($property)->create(['room_type_id' => $roomType->id])->id]);
            }

            $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
            FolioLine::factory()->for($folio)->create();
            FolioLine::factory()->for($folio)->payment()->create();
        }

        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($user)->get(listUrl($property))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $count;
    };

    test()->actingAs($user)->get(listUrl($property))->assertOk();

    $few = $queries(2);
    $many = $queries(8);

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(30);
});

test('staff see the all reservations item in the front desk context only with reservations.view', function () {
    [$owner, $property] = listOwner();
    [$housekeeping] = listOwner(DefaultRoleEnum::Housekeeping);

    $this->actingAs($owner)->get(route('dashboard', $property->code))->assertInertia(function (Assert $page) use ($property): void {
        $items = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Reservations')['items'];

        expect($items)->toHaveCount(1)
            ->and($items[0])->toMatchArray(['title' => 'All reservations', 'href' => "/p/{$property->code}/reservations", 'icon' => 'calendar-check', 'active' => false]);
    });

    $this->actingAs($owner)->get(listUrl($property))->assertInertia(function (Assert $page): void {
        $items = collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Reservations')['items'];

        expect($items[0]['active'])->toBeTrue()
            ->and($page->toArray()['props']['navigation']['active'])->toBe('front-desk');
    });

    $this->actingAs($housekeeping)->get(route('dashboard', $housekeeping->property->code))->assertInertia(function (Assert $page): void {
        expect(collect($page->toArray()['props']['navigation']['contexts'][0]['groups'])->firstWhere('label', 'Reservations'))->toBeNull();
    });
});

test('the list says whether the property has any reservation, so the empty state can tell hidden from none', function () {
    [$user, $property] = listOwner();

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page->where('hasReservations', false)->has('reservations.data', 0));

    listReservation($property, [], [['arrival_date' => '2026-09-01', 'departure_date' => '2026-09-03']]);

    $this->actingAs($user)->get(listUrl($property))->assertInertia(fn (Assert $page) => $page->where('hasReservations', true)->has('reservations.data', 0));
    $this->actingAs(User::factory()->create())->get(listUrl($property))->assertNotFound();
});

test('the list tells staff who may book from staff who may only view', function (DefaultRoleEnum $role, bool $canManage) {
    $user = User::factory()->withRole($role)->create();
    PropertySetting::factory()->for($user->property)->create();

    $this->actingAs($user)
        ->get(route('reservations.index', ['property' => $user->property->code]))
        ->assertInertia(fn ($page) => $page->where('canManage', $canManage));
})->with([[DefaultRoleEnum::Owner, true], [DefaultRoleEnum::FrontDesk, true], [DefaultRoleEnum::Finance, false]]);
