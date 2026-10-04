<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewReservationData;
use App\Enums\BillingMode;
use App\Enums\ReservationSource;
use App\Models\Bed;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A property with a PerRoom plan on a room type with `$rooms` rooms and a PerBed plan on a dorm with `$beds` beds,
 * both priced from the returned arrival date (30 days from now) for 8 nights.
 *
 * @return array{property: Property, user: User, guest: Guest, plan: RatePlan, roomType: RoomType, bedPlan: RatePlan, dorm: RoomType, arrival: CarbonImmutable}
 */
function createSetup(int $rooms = 2, int $beds = 3): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count($rooms)->for($property)->create(['room_type_id' => $roomType->id]);
    $plan->roomTypes()->attach($roomType);

    $bedPlan = RatePlan::factory()->perBed()->for($property)->create();
    $dorm = RoomType::factory()->bed()->for($property)->create();
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id]);
    Bed::factory()->count($beds)->create(['room_id' => $dormRoom->id]);
    $bedPlan->roomTypes()->attach($dorm);

    $arrival = CarbonImmutable::now()->addDays(30)->startOfDay();

    for ($night = 0; $night < 8; $night++) {
        $date = $arrival->addDays($night)->toDateString();
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => '100.00']);
        Rate::factory()->create(['rate_plan_id' => $bedPlan->id, 'room_type_id' => $dorm->id, 'date' => $date, 'amount' => '40.00']);
    }

    return [
        'property' => $property,
        'user' => User::factory()->for($property)->create(),
        'guest' => Guest::factory()->create(['property_id' => $property->id]),
        'plan' => $plan,
        'roomType' => $roomType,
        'bedPlan' => $bedPlan,
        'dorm' => $dorm,
        'arrival' => $arrival,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function roomLine(array $setup, int $nights = 2, array $overrides = []): array
{
    return [
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $setup['plan']->id,
        'arrivalDate' => $setup['arrival']->toDateString(),
        'departureDate' => $setup['arrival']->addDays($nights)->toDateString(),
        'adults' => 2,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bedLine(array $setup, int $beds, int $nights = 2, array $overrides = []): array
{
    return roomLine($setup, $nights, ['roomTypeId' => $setup['dorm']->id, 'ratePlanId' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => $beds, ...$overrides]);
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 */
function createReservation(array $setup, array $lines, array $overrides = [], bool $fromChannel = false): ReservationResult
{
    return app(CreateReservation::class)($setup['user'], $setup['property'], NewReservationData::from([
        'bookerGuestId' => $setup['guest']->id,
        'source' => ReservationSource::Direct->value,
        'units' => $lines,
        ...$overrides,
    ]), $fromChannel);
}

function expectNothingCreated(): void
{
    expect(Reservation::query()->count())->toBe(0)
        ->and(ReservationUnit::query()->count())->toBe(0)
        ->and(UnitNight::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0)
        ->and(DB::table('document_sequences')->where('last_number', '>', 0)->count())->toBe(0);
}

test('it creates a single room reservation', function () {
    $setup = createSetup();
    $company = Company::factory()->for($setup['property'])->create();

    $result = createReservation($setup, [roomLine($setup)], [
        'channelName' => 'Phone desk',
        'externalReference' => 'EXT-1',
        'companyId' => $company->id,
        'billingMode' => BillingMode::EachPays->value,
    ]);

    $reservation = $result->reservation;

    expect($result->isRefused())->toBeFalse()
        ->and($reservation->property_id)->toBe($setup['property']->id)
        ->and($reservation->booker_guest_id)->toBe($setup['guest']->id)
        ->and($reservation->created_by)->toBe($setup['user']->id)
        ->and($reservation->source)->toBe(ReservationSource::Direct)
        ->and($reservation->billing_mode)->toBe(BillingMode::EachPays)
        ->and($reservation->status->value)->toBe('confirmed')
        ->and($reservation->channel_name)->toBe('Phone desk')
        ->and($reservation->external_reference)->toBe('EXT-1')
        ->and($reservation->company_id)->toBe($company->id)
        ->and($reservation->group_name)->toBeNull()
        ->and($reservation->units()->count())->toBe(1)
        ->and(UnitNight::query()->count())->toBe(2);
});

test('the reference comes from the reservation document sequence', function () {
    $setup = createSetup();

    $first = createReservation($setup, [roomLine($setup)])->reservation;
    $second = createReservation($setup, [roomLine($setup)])->reservation;

    $prefix = $setup['property']->fresh()->document_prefix;

    expect($first->reference)->toMatch('/^'.$prefix.'-\d{2}100001$/')
        ->and($second->reference)->toMatch('/^'.$prefix.'-\d{2}100002$/');
});

test('a group has a unit for each line with its own dates, room type and plan', function () {
    $setup = createSetup();

    $result = createReservation($setup, [
        roomLine($setup, 2),
        roomLine($setup, 3, ['arrivalDate' => $setup['arrival']->addDay()->toDateString(), 'departureDate' => $setup['arrival']->addDays(4)->toDateString()]),
        bedLine($setup, 1),
    ], ['groupName' => 'Wedding party']);

    $units = $result->reservation->units()->orderBy('id')->get();

    expect($result->reservation->group_name)->toBe('Wedding party')
        ->and($units)->toHaveCount(3)
        ->and($units->pluck('room_type_id')->all())->toBe([$setup['roomType']->id, $setup['roomType']->id, $setup['dorm']->id])
        ->and($units->pluck('arrival_date')->map->toDateString()->all())->toBe([
            $setup['arrival']->toDateString(),
            $setup['arrival']->addDay()->toDateString(),
            $setup['arrival']->toDateString(),
        ]);
});

test('a bed line adds one unit for each bed', function () {
    $setup = createSetup();

    $result = createReservation($setup, [bedLine($setup, 3, 2, ['childAges' => []])]);

    $units = $result->reservation->units;

    expect($units)->toHaveCount(3)
        ->and($units->pluck('adults')->unique()->all())->toBe([1])
        ->and(UnitNight::query()->count())->toBe(6)
        ->and(UnitNight::query()->pluck('amount')->unique()->all())->toBe(['40.00'])
        ->and(app(AvailabilityService::class)->availableForStay($setup['dorm'], Date::parse($setup['arrival']), Date::parse($setup['arrival']->addDays(2))))->toBe(0);
});

test('a bed line with an override gives every bed the same total', function () {
    $setup = createSetup();

    createReservation($setup, [bedLine($setup, 2, 2, ['overrideTotal' => '50.00', 'overrideReason' => 'Hostel deal'])]);

    $totals = UnitNight::query()->get()->groupBy('reservation_unit_id')->map(fn ($nights): float => (float) $nights->sum('amount'));

    expect($totals->values()->all())->toBe([50.0, 50.0]);
});

test('a room type that sells rooms refuses more than one bed', function () {
    $setup = createSetup();

    $result = createReservation($setup, [roomLine($setup), roomLine($setup, 2, ['beds' => 2])]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_INVALID_STAY)
        ->and($result->lineIndex)->toBe(1);
    expectNothingCreated();
});

test('a bed line needs at least one bed', function () {
    $setup = createSetup();

    expect(createReservation($setup, [bedLine($setup, 0)])->refusal)->toBe(ReservationResult::REFUSAL_INVALID_STAY);
    expectNothingCreated();
});

test('a refused line saves nothing and names the line and the night', function () {
    $setup = createSetup();

    $result = createReservation($setup, [
        roomLine($setup),
        roomLine($setup, 2, ['adults' => 0]),
        roomLine($setup),
    ]);

    expect($result->reservation)->toBeNull()
        ->and($result->refusal)->toBe(ReservationResult::REFUSAL_ADULTS_REQUIRED)
        ->and($result->lineIndex)->toBe(1);
    expectNothingCreated();

    Rate::query()->where('room_type_id', $setup['roomType']->id)->where('date', $setup['arrival']->addDay()->toDateString())->update(['amount' => null]);
    $quote = createReservation($setup, [roomLine($setup, 3)]);

    expect($quote->refusal)->toBe('rate_missing')
        ->and($quote->date->toDateString())->toBe($setup['arrival']->addDay()->toDateString());
    expectNothingCreated();
});

test('a bed line that runs out of beds rolls back the beds already added', function () {
    $setup = createSetup(beds: 3);

    $result = createReservation($setup, [roomLine($setup), bedLine($setup, 4)]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and($result->lineIndex)->toBe(1);
    expectNothingCreated();
});

test('two lines of one room type cannot sell more rooms than there are', function () {
    $setup = createSetup(rooms: 1);

    $result = createReservation($setup, [roomLine($setup), roomLine($setup)]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE)
        ->and($result->lineIndex)->toBe(1);
    expectNothingCreated();
});

test('the room types of the request are locked in id order before the first line', function () {
    $setup = createSetup();
    DB::enableQueryLog();

    createReservation($setup, [bedLine($setup, 1), roomLine($setup)]);

    $queries = collect(DB::getQueryLog());
    $lock = $queries->search(fn (array $query): bool => str_contains($query['query'], 'from "room_types"') && str_contains($query['query'], 'for update') && str_contains($query['query'], 'order by "id"'));
    $insert = $queries->search(fn (array $query): bool => str_contains($query['query'], 'insert into "reservations"'));

    expect($lock)->not->toBeFalse()
        ->and($lock)->toBeLessThan($insert);
});

test('an empty request is refused', function () {
    $setup = createSetup();

    $result = createReservation($setup, []);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_UNITS_REQUIRED)
        ->and($result->lineIndex)->toBeNull();
    expectNothingCreated();
});

test('a booker of another property is refused', function () {
    $setup = createSetup();
    $setup['guest'] = Guest::factory()->create();

    expect(createReservation($setup, [roomLine($setup)])->refusal)->toBe(ReservationResult::REFUSAL_BOOKER_NOT_FOUND);
    expectNothingCreated();
});

test('a merged or anonymized booker is refused', function (string $state) {
    $setup = createSetup();
    $setup['guest']->forceFill($state === 'merged'
        ? ['merged_into_id' => Guest::factory()->create(['property_id' => $setup['guest']->property_id])->id]
        : ['anonymized_at' => now()])->save();

    expect(createReservation($setup, [roomLine($setup)])->refusal)->toBe(ReservationResult::REFUSAL_BOOKER_NOT_FOUND);
    expectNothingCreated();
})->with(['merged', 'anonymized']);

test('a reservation created from a channel skips the restriction and availability checks', function () {
    $setup = createSetup(rooms: 1);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => true]);

    $result = createReservation($setup, [roomLine($setup), roomLine($setup)], ['source' => ReservationSource::Channel->value], fromChannel: true);

    expect($result->isRefused())->toBeFalse()
        ->and($result->reservation->units)->toHaveCount(2);
});

test('a Channel source without the channel flag gets every check', function () {
    $setup = createSetup(rooms: 1);
    Rate::query()->where('date', $setup['arrival']->toDateString())->update(['stop_sell' => true]);

    $result = createReservation($setup, [roomLine($setup)], ['source' => ReservationSource::Channel->value]);

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_RESTRICTED);
    expectNothingCreated();
});

test('it logs the reservation once with its units', function () {
    $setup = createSetup();

    createReservation($setup, [roomLine($setup), bedLine($setup, 2)]);

    $created = Activity::query()->where('event', 'reservation.created')->sole();

    expect($created->causer_id)->toBe($setup['user']->id)
        ->and($created->properties['units'])->toBe(3)
        ->and(Activity::query()->where('event', 'reservation.unit_added')->count())->toBe(3);
});

test('each line keeps its own add-ons and a bed line gives every bed the same ones', function () {
    $setup = createSetup(beds: 3);
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00']);
    $perNight = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '10.00', 'charging_unit' => 'per_night']);

    $result = createReservation($setup, [
        roomLine($setup, 2, ['addOns' => [['chargeItemId' => $item->id, 'quantity' => 2]]]),
        bedLine($setup, 3, 2, ['addOns' => [['chargeItemId' => $perNight->id]]]),
    ]);

    expect($result->isRefused())->toBeFalse()
        ->and(UnitAddOn::query()->where('charge_item_id', $item->id)->count())->toBe(1)
        ->and(UnitAddOn::query()->where('charge_item_id', $perNight->id)->count())->toBe(6)
        ->and(ReservationUnit::query()->whereHas('addOns')->count())->toBe(4);
});

test('a refused add-on rolls back the whole booking and does not use up the reference', function () {
    $setup = createSetup();
    $item = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00', 'allow_override' => false]);

    $result = createReservation($setup, [
        roomLine($setup, 2, ['addOns' => [['chargeItemId' => $item->id]]]),
        roomLine($setup, 2, ['addOns' => [['chargeItemId' => $item->id, 'unitAmount' => '1.00']]]),
    ]);

    expect($result->reservation)->toBeNull()
        ->and($result->refusal)->toBe('amount_override_not_allowed')
        ->and($result->lineIndex)->toBe(1)
        ->and(UnitAddOn::query()->count())->toBe(0);
    expectNothingCreated();
});
