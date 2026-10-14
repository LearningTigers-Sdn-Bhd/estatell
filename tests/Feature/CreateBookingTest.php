<?php

require_once __DIR__.'/Support/BookingSetup.php';

use App\Actions\Reservations\AssignmentResult;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewBookingData;
use App\Enums\ReservationSource;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingRoomLine(array $setup, array $overrides = []): array
{
    return ['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 2, ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bookingBedLine(array $setup, int $beds, array $overrides = []): array
{
    return ['roomTypeId' => $setup['dorm']->id, 'ratePlanId' => $setup['bedPlan']->id, 'arrivalDate' => '2026-10-20', 'departureDate' => '2026-10-22', 'adults' => 1, 'beds' => $beds, ...$overrides];
}

/**
 * @param  array<string, mixed>  $data
 */
function createBooking(array $setup, array $data): BookingResult
{
    return app(CreateBooking::class)($setup['user'], $setup['property'], NewBookingData::from([
        'source' => ReservationSource::Phone->value,
        ...$data,
    ]));
}

function expectNothingBooked(int $guests): void
{
    expect(Reservation::query()->count())->toBe(0)
        ->and(UnitGuest::query()->count())->toBe(0)
        ->and(UnitNight::query()->count())->toBe(0)
        ->and(Guest::query()->count())->toBe($guests)
        ->and(Activity::query()->count())->toBe(0);
}

test('a booking saves the reservation, its guests, rooms and notes in one go', function () {
    $setup = bookingSetup();

    $result = createBooking($setup, [
        'units' => [bookingRoomLine($setup), bookingBedLine($setup, 2)],
        'groupName' => 'Tan family trip',
        'notes' => '  Late arrival, needs a cot.  ',
        'guests' => [
            ['guestId' => $setup['guest']->id, 'unitIndex' => 0],
            ['profile' => ['full_name' => 'Ahmad Faiz', 'phone' => '+60138882211'], 'unitIndex' => 1],
            ['profile' => ['full_name' => 'Nur Aina'], 'unitIndex' => 2],
        ],
        'picks' => [
            ['unitIndex' => 0, 'roomId' => $setup['rooms'][1]->id],
            ['unitIndex' => 1, 'bedId' => $setup['beds'][2]->id],
            ['unitIndex' => 2],
        ],
    ]);

    expect($result->isRefused())->toBeFalse();

    $reservation = $result->reservation->load('units');
    $units = $reservation->units->sortBy('id')->values();

    expect($reservation->booker_guest_id)->toBe($setup['guest']->id)
        ->and($reservation->group_name)->toBe('Tan family trip')
        ->and($reservation->notes)->toBe('Late arrival, needs a cot.')
        ->and($units)->toHaveCount(3)
        ->and(Guest::query()->where('property_id', $setup['property']->id)->pluck('full_name')->sort()->values()->all())->toBe(['Ahmad Faiz', 'Nur Aina', 'Tan Wei Ming'])
        ->and($units[0]->guests()->sole()->guest_id)->toBe($setup['guest']->id)
        ->and($units[1]->guests()->sole()->guest->full_name)->toBe('Ahmad Faiz')
        ->and($units[1]->guests()->sole()->is_primary)->toBeTrue()
        ->and($units[0]->nights()->pluck('room_id')->unique()->all())->toBe([$setup['rooms'][1]->id])
        ->and($units[1]->nights()->pluck('bed_id')->unique()->all())->toBe([$setup['beds'][2]->id])
        ->and($units[2]->nights()->whereNotNull('bed_id')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.created')->sole()->properties['has_notes'])->toBeTrue()
        ->and(Activity::query()->where('event', 'guest.created')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.room_assigned')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.bed_assigned')->count())->toBe(1);
});

test('the booker may not stay, and units may have no guest or room', function () {
    $setup = bookingSetup();

    $result = createBooking($setup, [
        'units' => [bookingRoomLine($setup)],
        'guests' => [['guestId' => $setup['guest']->id]],
    ]);

    $reservation = $result->reservation;

    expect($result->isRefused())->toBeFalse()
        ->and($reservation->notes)->toBeNull()
        ->and(UnitGuest::query()->count())->toBe(0)
        ->and(UnitNight::query()->whereNotNull('room_id')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.created')->sole()->properties['has_notes'])->toBeFalse();
});

test('a linked guest is changed only when the profile update is on', function (bool $update) {
    $setup = bookingSetup();

    createBooking($setup, [
        'units' => [bookingRoomLine($setup)],
        'guests' => [['guestId' => $setup['guest']->id, 'profile' => ['full_name' => 'Tan W. M.', 'email' => 'tan@example.com'], 'updateProfile' => $update]],
    ]);

    $setup['guest']->refresh();

    expect($setup['guest']->full_name)->toBe($update ? 'Tan W. M.' : 'Tan Wei Ming')
        ->and($setup['guest']->email)->toBe($update ? 'tan@example.com' : $setup['guest']->email)
        ->and(Activity::query()->where('event', 'guest.updated')->count())->toBe($update ? 1 : 0);
})->with([true, false]);

test('a refused step rolls back the guests, the reservation and the reference number', function (string $case) {
    $setup = bookingSetup();
    $booker = ['profile' => ['full_name' => 'New Booker'], 'unitIndex' => 0];
    [$data, $refusal, $expected] = match ($case) {
        'a line with no stock' => [['units' => [bookingRoomLine($setup), bookingRoomLine($setup), bookingRoomLine($setup)]], ReservationResult::REFUSAL_NOT_AVAILABLE, ['line' => 2]],
        'a booker with no name' => [['guests' => [['profile' => ['full_name' => ' ']]]], BookingResult::REFUSAL_GUEST_NAME_REQUIRED, ['guest' => 0]],
        'a second guest in a bed unit' => [['guests' => [[...$booker, 'unitIndex' => 1], ['profile' => ['full_name' => 'Other'], 'unitIndex' => 1]]], AttachGuestResult::REFUSAL_BED_UNIT_FULL, ['guest' => 1, 'unit' => 1]],
        'a guest on a unit that does not exist' => [['guests' => [[...$booker, 'unitIndex' => 9]]], BookingResult::REFUSAL_UNIT_NOT_FOUND, ['guest' => 0, 'unit' => 9]],
        'a bed that does not exist' => [['picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id], ['unitIndex' => 1, 'bedId' => 0]]], AssignmentResult::REFUSAL_BED_NOT_FOUND, ['unit' => 1]],
    };

    $result = createBooking($setup, [
        'units' => [bookingRoomLine($setup), bookingBedLine($setup, 2)],
        'guests' => [$booker],
        ...$data,
    ]);

    expect($result->refusal)->toBe($refusal)
        ->and(array_filter(['line' => $result->lineIndex, 'guest' => $result->guestIndex, 'unit' => $result->unitIndex], fn (?int $value) => $value !== null))->toBe($expected);
    expectNothingBooked(1);

    $ok = createBooking($setup, ['units' => [bookingRoomLine($setup)], 'guests' => [['guestId' => $setup['guest']->id]]]);

    expect($ok->reservation->reference)->toEndWith('0001');
})->with(['a line with no stock', 'a booker with no name', 'a second guest in a bed unit', 'a guest on a unit that does not exist', 'a bed that does not exist']);

test('a room picked twice is refused on the second unit', function () {
    $setup = bookingSetup();

    $result = createBooking($setup, [
        'units' => [bookingRoomLine($setup), bookingRoomLine($setup)],
        'guests' => [['guestId' => $setup['guest']->id]],
        'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id], ['unitIndex' => 1, 'roomId' => $setup['rooms'][0]->id]],
    ]);

    expect($result->refusal)->toBe(AssignmentResult::REFUSAL_ROOM_NOT_FREE)
        ->and($result->unitIndex)->toBe(1)
        ->and($result->date->toDateString())->toBe('2026-10-20');
    expectNothingBooked(1);
});

test('a booking with no guest tab or an unknown booker is refused', function () {
    $setup = bookingSetup();

    expect(createBooking($setup, ['units' => [bookingRoomLine($setup)], 'guests' => []])->refusal)->toBe(BookingResult::REFUSAL_BOOKER_REQUIRED)
        ->and(createBooking($setup, ['units' => [bookingRoomLine($setup)], 'guests' => [['guestId' => Guest::factory()->create()->id]]])->refusal)->toBe(ReservationResult::REFUSAL_BOOKER_NOT_FOUND);
    expectNothingBooked(2);
});

test('a guest of another property cannot be attached or updated', function () {
    $setup = bookingSetup();
    $foreign = Guest::factory()->create();

    $result = createBooking($setup, [
        'units' => [bookingRoomLine($setup)],
        'guests' => [['guestId' => $setup['guest']->id, 'unitIndex' => 0], ['guestId' => $foreign->id, 'profile' => ['full_name' => 'Changed'], 'updateProfile' => true, 'unitIndex' => 0]],
    ]);

    expect($result->refusal)->toBe(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND)
        ->and($result->guestIndex)->toBe(1)
        ->and($foreign->refresh()->full_name)->not->toBe('Changed');
});
