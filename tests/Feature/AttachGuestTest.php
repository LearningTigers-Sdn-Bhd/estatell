<?php

use App\Actions\Reservations\AttachGuest;
use App\Actions\Reservations\AttachGuestResult;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitGuest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function attachGuest(User $actor, ReservationUnit $unit, Guest $guest): AttachGuestResult
{
    return app(AttachGuest::class)($actor, $unit, $guest);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function attachGuestGuestOf(ReservationUnit $unit, array $attributes = []): Guest
{
    return Guest::factory()->create(['property_id' => $unit->property_id, ...$attributes]);
}

function expectNoGuestAttached(): void
{
    expect(UnitGuest::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
}

test('the first guest of a unit is primary and the second is not', function () {
    $unit = ReservationUnit::factory()->create();
    $actor = User::factory()->create();
    $first = attachGuestGuestOf($unit);
    $second = attachGuestGuestOf($unit);

    $one = attachGuest($actor, $unit, $first);
    $two = attachGuest($actor, $unit, $second);

    expect($one->isRefused())->toBeFalse()
        ->and($one->refusal)->toBeNull()
        ->and($one->unitGuest->is_primary)->toBeTrue()
        ->and($two->unitGuest->is_primary)->toBeFalse()
        ->and($unit->guests()->count())->toBe(2)
        ->and($unit->guests()->where('is_primary', true)->sole()->guest_id)->toBe($first->id);
});

test('it logs the attach on the reservation with ids only', function () {
    $unit = ReservationUnit::factory()->create();
    $actor = User::factory()->create();
    $guest = attachGuestGuestOf($unit);

    attachGuest($actor, $unit, $guest);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('reservation.guest_attached')
        ->and($activity->subject_type)->toBe((new Reservation)->getMorphClass())
        ->and($activity->subject_id)->toBe($unit->reservation_id)
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties->all())->toBe([
            'property_id' => $unit->property_id,
            'reservation_unit_id' => $unit->id,
            'guest_id' => $guest->id,
            'is_primary' => true,
        ]);
});

test('a blacklisted guest can be attached', function () {
    $unit = ReservationUnit::factory()->create();

    $result = attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit, ['blacklisted_at' => now(), 'blacklist_reason' => 'Damaged the room']));

    expect($result->isRefused())->toBeFalse();
});

test('a unit that is checked in takes a guest', function () {
    $unit = ReservationUnit::factory()->checkedIn()->create();

    expect(attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit))->isRefused())->toBeFalse();
});

test('it refuses a guest of another property, a merged guest and an anonymized guest', function (string $case) {
    $unit = ReservationUnit::factory()->create();
    $guest = match ($case) {
        'other property' => Guest::factory()->create(),
        'merged' => attachGuestGuestOf($unit, ['merged_into_id' => attachGuestGuestOf($unit)->id]),
        'anonymized' => attachGuestGuestOf($unit, ['anonymized_at' => now()]),
    };

    $result = attachGuest(User::factory()->create(), $unit, $guest);

    expect($result->refusal)->toBe(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND)
        ->and($result->unitGuest)->toBeNull();
    expectNoGuestAttached();
})->with(['other property', 'merged', 'anonymized']);

test('it refuses a unit that is not open', function (string $state) {
    $unit = ReservationUnit::factory()->{$state}()->create();

    expect(attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit))->refusal)->toBe(AttachGuestResult::REFUSAL_UNIT_NOT_OPEN);
    expectNoGuestAttached();
})->with(['cancelled', 'noShow', 'checkedOut']);

test('it refuses a guest already on the unit', function () {
    $unit = ReservationUnit::factory()->create();
    $guest = attachGuestGuestOf($unit);
    attachGuest(User::factory()->create(), $unit, $guest);

    $result = attachGuest(User::factory()->create(), $unit, $guest);

    expect($result->refusal)->toBe(AttachGuestResult::REFUSAL_ALREADY_ATTACHED)
        ->and(UnitGuest::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1);
});

test('a bed unit takes one guest and a room unit has no limit', function () {
    $bedType = RoomType::factory()->bed()->create();
    $bed = ReservationUnit::factory()->create(['property_id' => $bedType->property_id, 'room_type_id' => $bedType->id, 'reservation_id' => Reservation::factory()->create(['property_id' => $bedType->property_id])->id]);
    $room = ReservationUnit::factory()->create();
    $actor = User::factory()->create();

    expect(attachGuest($actor, $bed, attachGuestGuestOf($bed))->isRefused())->toBeFalse();

    $refused = attachGuest($actor, $bed, attachGuestGuestOf($bed));

    expect($refused->refusal)->toBe(AttachGuestResult::REFUSAL_BED_UNIT_FULL)
        ->and($bed->guests()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe(1);

    foreach (range(1, 4) as $ignored) {
        expect(attachGuest($actor, $room, attachGuestGuestOf($room))->isRefused())->toBeFalse();
    }

    expect($room->guests()->count())->toBe(4);
});

test('the unit is read with a row lock before the checks', function () {
    $unit = ReservationUnit::factory()->create();
    $guest = attachGuestGuestOf($unit);
    DB::enableQueryLog();

    attachGuest(User::factory()->create(), $unit, $guest);

    $queries = collect(DB::getQueryLog())->pluck('query');

    $lock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "reservation_units"') && str_contains($sql, 'for update'));
    $write = $queries->search(fn (string $sql): bool => str_contains($sql, 'insert into "unit_guests"'));

    expect($lock)->not->toBeFalse()
        ->and($lock)->toBeLessThan($write);
});
