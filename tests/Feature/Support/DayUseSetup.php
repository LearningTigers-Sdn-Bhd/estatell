<?php

use App\Actions\Catalogues\SeedTaxes;
use App\Actions\Catalogues\SeedTransactionCodes;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewReservationData;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReservationSource;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A property with a Day use plan (2 to 6 hours, sold between 08:00 and 18:00) on a room type with two rooms, priced
 * from 2h 60.00 and +20.00 an hour for 8 dates from `date` (30 days from now), and a Basic Rate overnight plan priced at
 * 100.00 on the same room type.
 *
 * @return array{property: Property, user: User, guest: Guest, plan: RatePlan, basic: RatePlan, roomType: RoomType, rooms: list<Room>, date: CarbonImmutable}
 */
function dayUseSetup(int $rooms = 2, ?User $owner = null): array
{
    $property = $owner?->property ?? Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $roomList = Room::factory()->count($rooms)->for($property)->create(['room_type_id' => $roomType->id])->all();
    $plan = RatePlan::factory()->for($property)->dayUse(2, 6, '08:00', '18:00')->create(['name' => 'Day use']);
    $plan->roomTypes()->attach($roomType);
    $basic = RatePlan::factory()->for($property)->create(['name' => 'Basic']);
    $basic->roomTypes()->attach($roomType);
    $date = CarbonImmutable::now()->addDays(30)->startOfDay();

    for ($day = 0; $day < 8; $day++) {
        $rate = Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date->addDays($day)->toDateString(), 'amount' => null]);

        foreach (range(2, 6) as $hours) {
            $rate->hourPrices()->create(['hours' => $hours, 'amount' => number_format(60 + ($hours - 2) * 20, 2, '.', '')]);
        }

        Rate::factory()->create(['rate_plan_id' => $basic->id, 'room_type_id' => $roomType->id, 'date' => $date->addDays($day)->toDateString(), 'amount' => '100.00']);
    }

    return [
        'property' => $property,
        'user' => $owner ?? User::factory()->for($property)->create(),
        'guest' => Guest::factory()->create(['property_id' => $property->id]),
        'plan' => $plan,
        'basic' => $basic,
        'roomType' => $roomType,
        'rooms' => $roomList,
        'date' => $date,
    ];
}

/**
 * The transaction codes and tax presets of the property, so DAY_USE exists and its taxes can be set.
 */
function seedDayUseCodes(Property $property): void
{
    app(SeedTransactionCodes::class)($property, 'MY');
    app(SeedTaxes::class)($property, 'MY');
}

/**
 * One day use line: 10:00 for 4 hours in the first room, unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dayUseLine(array $setup, array $overrides = []): array
{
    return [
        'roomTypeId' => $setup['roomType']->id,
        'ratePlanId' => $setup['plan']->id,
        'arrivalDate' => $setup['date']->toDateString(),
        'departureDate' => $setup['date']->toDateString(),
        'adults' => 2,
        'stayType' => 'day_use',
        'startTime' => '10:00',
        'hours' => 4,
        'roomId' => $setup['rooms'][0]->id,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $overrides
 */
function createDayUseReservation(array $setup, array $lines, array $overrides = [], bool $fromChannel = false): ReservationResult
{
    return app(CreateReservation::class)($setup['user'], $setup['property'], NewReservationData::from([
        'bookerGuestId' => $setup['guest']->id,
        'source' => ReservationSource::Direct->value,
        'units' => $lines,
        ...$overrides,
    ]), $fromChannel);
}

/**
 * Expect the database to refuse what the callback writes. It runs in a savepoint, so the test transaction stays usable.
 */
function expectDatabaseRefuses(Closure $callback, ?string $constraint = null): void
{
    $thrown = null;

    try {
        DB::transaction($callback);
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);

    if ($constraint !== null) {
        expect($thrown->getMessage())->toContain($constraint);
    }
}

/**
 * The same setup for an owner of the property, who may use the booking pages.
 *
 * @return array<string, mixed>
 */
function dayUseHttpSetup(): array
{
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create();

    return dayUseSetup(2, $owner);
}
