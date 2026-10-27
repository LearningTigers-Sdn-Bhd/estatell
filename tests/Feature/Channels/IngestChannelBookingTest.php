<?php

use App\Actions\Channels\IngestChannelBooking;
use App\Actions\Channels\StoreChannelRevision;
use App\Enums\ReservationStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\ChannelBookingRevision;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Notifications\ChannelBookingCancelled;
use App\Notifications\ChannelBookingReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ChannelBooking.php';

function ingest(array $setup, array $attributes = []): array
{
    $revision = storeChannelRevision($setup, $attributes);
    $result = app(IngestChannelBooking::class)($revision);

    return [$revision->fresh(), $result];
}

test('a new revision becomes a reservation with the OTA source and price, and is acknowledged after the save', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    Notification::fake();

    [$revision, $result] = ingest($setup);

    $reservation = Reservation::query()->with(['units.nights', 'bookingSource', 'booker'])->sole();
    expect($result->ok)->toBeTrue()
        ->and($reservation->external_reference)->toBe('BDC-9996013801')
        ->and($reservation->bookingSource->name)->toBe('Booking.com')
        ->and($reservation->booker->full_name)->toBe('Aina Rahman')
        ->and($reservation->created_by)->toBe($setup['user']->id)
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->units)->toHaveCount(1)
        ->and($reservation->units[0]->nights->sum(fn ($night) => (float) $night->amount))->toBe(200.0)
        ->and($revision->reservation_id)->toBe($reservation->id)
        ->and($revision->processed_at)->not->toBeNull()
        ->and($revision->acknowledged_at)->not->toBeNull()
        ->and($revision->error)->toBeNull()
        ->and(channexAcks())->toHaveCount(1);
    Notification::assertSentTo($setup['user'], ChannelBookingReceived::class);
});

test('one booking with two rooms is one reservation with two units', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();

    ingest($setup, ['rooms' => [channelRevisionRoom(), channelRevisionRoom(['amount' => '210.00'])]]);

    $reservation = Reservation::query()->withCount('units')->sole();
    expect($reservation->units_count)->toBe(2);
});

test('an existing guest with the same email is the booker', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'A. Rahman', 'email' => 'aina@example.com']);

    ingest($setup);

    expect(Reservation::query()->sole()->booker_guest_id)->toBe($guest->id)
        ->and(Guest::query()->where('property_id', $setup['property']->id)->where('full_name', 'Aina Rahman')->exists())->toBeFalse();
});

test('a stay with no rates and no room left is still accepted at the OTA price', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    $room = ['checkin_date' => '2026-12-01', 'checkout_date' => '2026-12-04', 'amount' => '301.00'];

    [, $result] = ingest($setup, ['arrival_date' => '2026-12-01', 'departure_date' => '2026-12-04', 'rooms' => array_fill(0, 3, channelRevisionRoom($room))]);

    $reservation = Reservation::query()->with('units.nights')->sole();
    expect($result->ok)->toBeTrue()
        ->and($reservation->units)->toHaveCount(3)
        ->and($reservation->units[0]->nights->pluck('amount')->map(fn ($amount) => (float) $amount)->sum())->toBe(301.0);
});

test('an unmapped room is refused: no reservation, an error, and no acknowledgement', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();

    [$revision, $result] = ingest($setup, ['rooms' => [channelRevisionRoom(['room_type_id' => null, 'rate_plan_id' => null])]]);

    expect($result->ok)->toBeFalse()
        ->and(Reservation::query()->count())->toBe(0)
        ->and($revision->error)->toContain('not mapped')
        ->and($revision->processed_at)->toBeNull()
        ->and($revision->acknowledged_at)->toBeNull()
        ->and(channexAcks())->toBe([]);
});

test('a booking in another currency is refused', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();

    [$revision] = ingest($setup, ['currency' => 'USD']);

    expect($revision->error)->toContain('USD')
        ->and(Reservation::query()->count())->toBe(0);
});

test('a retry after the mapping is fixed saves the booking once', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    $setup['channelProperty']->ratePlanMappings()->delete();
    [$revision] = ingest($setup);
    expect($revision->error)->not->toBeNull();

    $setup['channelProperty']->ratePlanMappings()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'external_rate_plan_id' => 'plan-1']);
    $result = app(IngestChannelBooking::class)($revision);

    expect($result->ok)->toBeTrue()
        ->and(Reservation::query()->count())->toBe(1)
        ->and($revision->fresh()->error)->toBeNull()
        ->and($revision->fresh()->acknowledged_at)->not->toBeNull();
});

test('when Channex does not take the acknowledgement the booking stays saved, and the next try only acknowledges', function () {
    $setup = channelBookingSetup();
    Notification::fake();
    fakeChannexAck(500);

    [$revision, $result] = ingest($setup);

    expect($result->ok)->toBeFalse()
        ->and($revision->processed_at)->not->toBeNull()
        ->and($revision->acknowledged_at)->toBeNull()
        ->and(Reservation::query()->count())->toBe(1);

    resetChannexFake();
    fakeChannexAck();
    $again = app(IngestChannelBooking::class)($revision);

    expect($again->ok)->toBeTrue()
        ->and(Reservation::query()->count())->toBe(1)
        ->and($revision->fresh()->acknowledged_at)->not->toBeNull();
    Notification::assertSentToTimes($setup['user'], ChannelBookingReceived::class, 1);
});

test('a modified revision changes the dates and adds a room', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    ingest($setup);

    [$revision, $result] = ingest($setup, [
        'status' => 'modified',
        'arrival_date' => '2026-10-21',
        'departure_date' => '2026-10-24',
        'rooms' => [
            channelRevisionRoom(['checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-24', 'amount' => '300.00']),
            channelRevisionRoom(['checkin_date' => '2026-10-21', 'checkout_date' => '2026-10-22', 'amount' => '100.00']),
        ],
    ]);

    $units = Reservation::query()->sole()->units()->orderBy('id')->get();
    expect($result->ok)->toBeTrue()
        ->and($units)->toHaveCount(2)
        ->and($units[0]->arrival_date->toDateString())->toBe('2026-10-21')
        ->and($units[0]->departure_date->toDateString())->toBe('2026-10-24')
        ->and($revision->reservation_id)->toBe(Reservation::query()->sole()->id);
});

/** The room revenue and discount codes a property gets when it is set up, which `ChangeNightPrice` needs. */
function channelPricingCodes(array $setup): void
{
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $setup['property']->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Discount)->create(['property_id' => $setup['property']->id]);
}

/** @return list<float> The night prices of the reservation's first unit, by date. */
function reservationNightAmounts(): array
{
    return Reservation::query()->sole()->units()->orderBy('id')->firstOrFail()->nights()->orderBy('date')->pluck('amount')->map(fn ($amount) => (float) $amount)->all();
}

test('a modified revision that lengthens the stay prices the added night at the OTA price', function () {
    $setup = channelBookingSetup();
    channelPricingCodes($setup);
    fakeChannexAck();
    ingest($setup);
    expect(reservationNightAmounts())->toBe([100.0, 100.0]);

    ingest($setup, [
        'status' => 'modified',
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'rooms' => [channelRevisionRoom(['checkout_date' => '2026-10-23', 'amount' => '450.00'])],
    ]);

    expect(reservationNightAmounts())->toBe([150.0, 150.0, 150.0]);
});

test('a modified revision with day amounts gives each night its own price', function () {
    $setup = channelBookingSetup();
    channelPricingCodes($setup);
    fakeChannexAck();
    ingest($setup);

    ingest($setup, [
        'status' => 'modified',
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'rooms' => [channelRevisionRoom([
            'checkout_date' => '2026-10-23',
            'amount' => '295.50',
            'days' => ['2026-10-20' => '80.00', '2026-10-21' => '120.00', '2026-10-22' => '95.50'],
        ])],
    ]);

    expect(reservationNightAmounts())->toBe([80.0, 120.0, 95.5]);
});

test('a modified revision with a new room price reprices the nights when the dates stay', function () {
    $setup = channelBookingSetup();
    channelPricingCodes($setup);
    fakeChannexAck();
    ingest($setup);

    ingest($setup, ['status' => 'modified', 'rooms' => [channelRevisionRoom(['amount' => '261.00'])]]);

    expect(reservationNightAmounts())->toBe([130.5, 130.5]);
});

test('a modified revision that keeps the price leaves the nights and the log alone', function () {
    $setup = channelBookingSetup();
    channelPricingCodes($setup);
    fakeChannexAck();
    ingest($setup);
    $before = Activity::query()->where('event', 'like', '%price%')->count();

    ingest($setup, ['status' => 'modified']);

    expect(reservationNightAmounts())->toBe([100.0, 100.0])
        ->and(Activity::query()->where('event', 'like', '%price%')->count())->toBe($before);
});

test('a modified revision with fewer rooms cancels the extra unit without a fee', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    ingest($setup, ['rooms' => [channelRevisionRoom(), channelRevisionRoom()]]);

    ingest($setup, ['status' => 'modified']);

    $units = Reservation::query()->sole()->units()->orderBy('id')->get();
    expect($units->pluck('status')->all())->toBe([UnitStatus::Confirmed, UnitStatus::Cancelled]);
});

test('a cancelled revision cancels the reservation and tells staff', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    Notification::fake();
    ingest($setup);

    [, $result] = ingest($setup, ['status' => 'cancelled']);

    $reservation = Reservation::query()->sole();
    expect($result->ok)->toBeTrue()
        ->and($reservation->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->units()->where('status', '<>', UnitStatus::Cancelled)->count())->toBe(0);
    Notification::assertSentTo($setup['user'], ChannelBookingCancelled::class);
});

test('a modified revision whose first revision was never saved is refused', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();

    [$revision, $result] = ingest($setup, ['status' => 'modified']);

    expect($result->ok)->toBeFalse()
        ->and($revision->error)->toContain('earlier revision')
        ->and(channexAcks())->toBe([]);
});

test('a property with no owner refuses the booking', function () {
    $setup = channelBookingSetup();
    fakeChannexAck();
    DB::table('model_has_roles')->where('model_id', $setup['user']->id)->delete();

    [$revision] = ingest($setup);

    expect($revision->error)->toContain('no owner')
        ->and(Reservation::query()->count())->toBe(0);
});

test('a duplicate revision is stored once', function () {
    $setup = channelBookingSetup();
    $payload = channelRevisionPayload(['id' => 'rev-1']);

    $first = app(StoreChannelRevision::class)($setup['channelProperty'], $payload);
    $second = app(StoreChannelRevision::class)($setup['channelProperty'], $payload);

    expect($second->id)->toBe($first->id)
        ->and(ChannelBookingRevision::query()->count())->toBe(1);
});

test('a revision with an unknown status is not stored', function () {
    $setup = channelBookingSetup();

    expect(app(StoreChannelRevision::class)($setup['channelProperty'], channelRevisionPayload(['status' => 'odd'])))->toBeNull()
        ->and(ChannelBookingRevision::query()->count())->toBe(0);
});

test('with auto-assign on each unit of a new OTA booking gets its own room', function () {
    $setup = channelBookingSetup();
    $setup['property']->settings->forceFill(['auto_assign_rooms' => true])->save();
    fakeChannexAck();

    ingest($setup, ['rooms' => [channelRevisionRoom(), channelRevisionRoom()]]);

    $rooms = Reservation::query()->sole()->units()->orderBy('id')->get()
        ->map(fn ($unit) => $unit->nights()->pluck('room_id')->unique()->values()->all());

    expect($rooms->flatten()->sort()->values()->all())->toBe(collect($setup['rooms'])->pluck('id')->sort()->values()->all());
});

test('a modified revision whose longer stay loses the room moves the unit to a room free for the whole stay', function () {
    $setup = channelBookingSetup();
    $setup['property']->settings->forceFill(['auto_assign_rooms' => true])->save();
    fakeChannexAck();
    ingest($setup);
    $unit = Reservation::query()->sole()->units()->sole();
    $first = $unit->nights()->value('room_id');
    $other = collect($setup['rooms'])->firstWhere(fn ($room) => $room->id !== $first);
    $taker = ReservationUnit::factory()->withNights()->create(['property_id' => $setup['property']->id, 'room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-22', 'departure_date' => '2026-10-23']);
    $taker->nights()->update(['room_id' => $first]);

    [, $result] = ingest($setup, [
        'status' => 'modified',
        'departure_date' => '2026-10-23',
        'rooms' => [channelRevisionRoom(['checkout_date' => '2026-10-23', 'amount' => '300.00'])],
    ]);

    expect($result->ok)->toBeTrue()
        ->and($unit->nights()->orderBy('date')->pluck('room_id')->all())->toBe([$other->id, $other->id, $other->id]);
});
