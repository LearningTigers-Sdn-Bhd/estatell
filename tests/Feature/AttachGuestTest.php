<?php

use App\Actions\Reservations\AttachGuest;
use App\Actions\Reservations\AttachGuestResult;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\Tax;
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
})->with(['cancelled', 'noShow']);

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

test('a checked-out unit takes a guest and posts nothing', function () {
    $unit = ReservationUnit::factory()->checkedOut()->create();
    $before = FolioLine::query()->count();

    $result = attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit));

    expect($result->isRefused())->toBeFalse()
        ->and($result->unitGuest->is_primary)->toBeTrue()
        ->and(FolioLine::query()->count())->toBe($before)
        ->and(Activity::query()->count())->toBe(1);
});

/**
 * A checked-out unit whose booker is Malaysian, for the tax warning tests.
 */
function attachGuestCheckedOutUnit(?string $bookerNationality = 'MY'): ReservationUnit
{
    $unit = ReservationUnit::factory()->checkedOut()->create();
    $unit->reservation->booker->update(['nationality_code' => $bookerNationality]);

    return $unit;
}

function attachGuestForeignTax(ReservationUnit $unit, array $attributes = []): Tax
{
    return Tax::factory()->create(['property_id' => $unit->property_id, 'name' => 'Tourism tax', 'foreign_guests_only' => true, ...$attributes]);
}

test('a new primary with another foreign status than the booker gets a tax warning', function () {
    $unit = attachGuestCheckedOutUnit('MY');
    attachGuestForeignTax($unit);

    $result = attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit, ['nationality_code' => 'SG']));

    expect($result->taxWarning)->toBe("Guest added. Tourism tax was charged on the booker's nationality (Malaysia). If it is different for this guest, correct it on the folio.");
});

test('several foreign-only taxes are named together', function () {
    $unit = attachGuestCheckedOutUnit('MY');
    attachGuestForeignTax($unit);
    attachGuestForeignTax($unit, ['name' => 'Heritage levy']);

    $result = attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit, ['nationality_code' => 'SG']));

    expect($result->taxWarning)->toContain('Heritage levy and Tourism tax');
});

test('there is no tax warning when nothing differs or nothing applies', function (string $case) {
    $unit = attachGuestCheckedOutUnit($case === 'booker empty' ? null : 'MY');
    $tax = attachGuestForeignTax($unit);
    $actor = User::factory()->create();
    $nationality = match ($case) {
        'guest empty' => null,
        'same status' => 'MY',
        default => 'SG',
    };

    if ($case === 'disabled tax') {
        $tax->update(['enabled' => false]);
    }

    if ($case === 'not primary') {
        attachGuest($actor, $unit, attachGuestGuestOf($unit, ['nationality_code' => 'MY']));
    }

    $result = attachGuest($actor, $unit, attachGuestGuestOf($unit, ['nationality_code' => $nationality]));

    expect($result->isRefused())->toBeFalse()
        ->and($result->taxWarning)->toBeNull();
})->with(['not primary', 'guest empty', 'booker empty', 'same status', 'disabled tax']);

test('a unit that is not checked out never warns', function () {
    $unit = ReservationUnit::factory()->checkedIn()->create();
    $unit->reservation->booker->update(['nationality_code' => 'MY']);
    attachGuestForeignTax($unit);

    $result = attachGuest(User::factory()->create(), $unit, attachGuestGuestOf($unit, ['nationality_code' => 'SG']));

    expect($result->taxWarning)->toBeNull();
});
