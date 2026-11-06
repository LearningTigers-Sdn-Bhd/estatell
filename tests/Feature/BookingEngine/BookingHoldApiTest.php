<?php

require_once __DIR__.'/../Support/BookingApiSetup.php';

use App\Actions\BookingEngine\ExpireBookingHolds;
use App\Actions\BookingEngine\PurgeBookingHoldGuestDetails;
use App\Actions\Reservations\AddUnit;
use App\Actions\Reservations\ReservationResult;
use App\Data\Reservations\NewUnitData;
use App\Enums\BookingHoldStatus;
use App\Events\InventoryChanged;
use App\Models\BookingClient;
use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

const BOOKING_HOLD_IP = '203.0.113.10';

/**
 * The body of a hold for one room for two nights, at the RM 100 a night of the setup.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function holdBody(array $setup, array $body = []): array
{
    return [
        'arrival' => '2026-10-20',
        'departure' => '2026-10-22',
        'total' => '200.00',
        'guest' => ['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '0123456789', 'nationality' => 'MY'],
        'lines' => [holdLine($setup)],
        ...$body,
    ];
}

/**
 * @param  array<string, mixed>  $setup
 * @return array<string, mixed>
 */
function holdLine(array $setup): array
{
    return ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2];
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function postHold(array $setup, array $body = [], array $headers = ['X-Guest-IP' => BOOKING_HOLD_IP], string $token = BOOKING_API_TOKEN): TestResponse
{
    return test()->postJson('/api/booking/v1/properties/'.$setup['property']->code.'/holds', holdBody($setup, $body), ['Authorization' => 'Bearer '.$token, ...$headers]);
}

/**
 * A hold of the setup's property and client, with one line of its room type.
 *
 * @param  array<string, mixed>  $setup
 */
function holdFor(array $setup, BookingClient $client, string $state = 'held'): BookingHold
{
    $factory = BookingHold::factory()->for($setup['property'])->state(['booking_client_id' => $client->id]);
    $hold = ($state === 'held' ? $factory : $factory->{$state}())->create();
    BookingHoldLine::factory()->create(['booking_hold_id' => $hold->id, 'room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id]);

    return $hold;
}

function roomsFree(RoomType $roomType): int
{
    return app(AvailabilityService::class)->availableForStay($roomType, now()->setDate(2026, 10, 20)->toImmutable(), now()->setDate(2026, 10, 22)->toImmutable());
}

it('holds a room for 20 minutes, and shows no guest details', function () {
    $setup = bookingApiProperty();
    $client = bookingMicrositeClient();

    $response = postHold($setup, ['special_requests' => 'Late arrival']);

    $response->assertCreated()
        ->assertJsonPath('status', 'held')
        ->assertJsonPath('online_total', '200.00')
        ->assertJsonPath('pay_at_property', '0.00')
        ->assertJsonPath('expires_at', now()->addMinutes(20)->toIso8601String())
        ->assertJsonMissingPath('guest')
        ->assertDontSee('Aisyah');

    $hold = BookingHold::query()->with('lines')->sole();

    expect($hold->token)->toBe($response->json('token'))
        ->and($hold->property_id)->toBe($setup['property']->id)
        ->and($hold->booking_client_id)->toBe($client->id)
        ->and($hold->guest_phone)->toBe('+60123456789')
        ->and($hold->special_requests)->toBe('Late arrival')
        ->and($hold->guest_ip_hash)->not->toBe(BOOKING_HOLD_IP)
        ->and($hold->lines->sole()->amount)->toBe('200.00')
        ->and($hold->lines->sole()->adults)->toBe(2)
        ->and(roomsFree($setup['roomType']))->toBe(1);
});

it('stores guest details encrypted', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup)->assertCreated();

    $row = DB::table('booking_holds')->sole();

    expect($row->guest_name)->not->toContain('Aisyah')
        ->and($row->guest_email)->not->toContain('aisyah')
        ->and($row->guest_phone)->not->toContain('6012')
        ->and($row->guest_nationality)->not->toBe('MY');
});

it('stores one row per child, and ignores the party of a bed', function () {
    $setup = bookingApiProperty();
    $setup['roomType']->forceFill(['max_children' => 2])->save();
    bookingMicrositeClient();

    postHold($setup, ['total' => '280.00', 'lines' => [
        [...holdLine($setup), 'child_ages' => [4, 9]],
        ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 3, 'child_ages' => [5]],
    ]])->assertCreated();

    [$room, $bed] = BookingHold::query()->sole()->lines()->with('children')->get()->all();

    expect($room->children->pluck('age')->all())->toBe([4, 9])
        ->and($bed->adults)->toBe(1)
        ->and($bed->children)->toBeEmpty();
});

it('prices the hold with the real nationality, and splits the desk taxes', function () {
    $setup = bookingApiProperty();
    bookingApiTaxes($setup['property'], $setup['roomCode']);
    bookingMicrositeClient();
    $guest = ['name' => 'John Smith', 'email' => 'john@example.com', 'phone' => '+447911123456', 'nationality' => 'GB'];

    postHold($setup, ['total' => '216.00', 'guest' => $guest])
        ->assertCreated()
        ->assertJsonPath('online_total', '216.00')
        ->assertJsonPath('pay_at_property', '20.00');
});

it('refuses a hold the server would not sell', function (string $code, Closure $change) {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup, $change($setup))->assertUnprocessable()->assertJsonPath('error.code', $code);

    expect(BookingHold::query()->count())->toBe(0);
})->with([
    'an arrival in the past' => ['outside_window', fn () => ['arrival' => '2026-10-10', 'departure' => '2026-10-12']],
    'an arrival over 6 months ahead' => ['outside_window', fn () => ['arrival' => '2027-05-01', 'departure' => '2027-05-02']],
    'a plan that does not sell the room type' => ['plan_not_available', fn (array $setup) => ['lines' => [['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 1]]]],
    'a plan of another property' => ['plan_not_available', function (array $setup) {
        $other = RatePlan::factory()->for(Property::factory())->create();

        return ['lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $other->id, 'adults' => 1]]];
    }],
    'too many adults' => ['too_many_adults', fn (array $setup) => ['lines' => [[...holdLine($setup), 'adults' => 40]]]],
    'more lines than rooms' => ['not_available', fn (array $setup) => ['total' => '600.00', 'lines' => [holdLine($setup), holdLine($setup), holdLine($setup)]]],
    'a total that changed' => ['price_changed', fn () => ['total' => '150.00']],
]);

it('refuses a plan that is not bookable online', function () {
    $setup = bookingApiProperty();
    $setup['plan']->forceFill(['bookable_online' => false])->save();
    bookingMicrositeClient();

    postHold($setup)->assertUnprocessable()->assertJsonPath('error.code', 'plan_not_available');
});

it('returns the current total when the price changed', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup, ['total' => '150.00'])->assertJsonPath('error.current_total', '200.00');
});

it('refuses the room when an earlier hold took the last one', function () {
    $setup = bookingApiProperty();
    Room::factory()->count(4)->for($setup['property'])->create(['room_type_id' => $setup['dorm']->id]);
    bookingMicrositeClient();

    postHold($setup)->assertCreated();
    postHold($setup, headers: ['X-Guest-IP' => '203.0.113.11'])->assertCreated();
    postHold($setup, headers: ['X-Guest-IP' => '203.0.113.12'])->assertUnprocessable()->assertJsonPath('error.code', 'not_available');
});

it('answers field errors in the one error shape', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup, ['guest' => []])->assertUnprocessable()
        ->assertJsonPath('error.code', 'invalid_fields')
        ->assertJsonMissingPath('errors')
        ->assertJsonStructure(['error' => ['message', 'fields' => ['guest.name']]]);
});

it('validates the guest details and the lines', function (string $field, array $body) {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup, $body)->assertUnprocessable()->assertJsonValidationErrors($field, 'error.fields');
})->with([
    'no name' => ['guest.name', ['guest' => ['email' => 'a@example.com', 'phone' => '0123456789', 'nationality' => 'MY']]],
    'no email' => ['guest.email', ['guest' => ['name' => 'A', 'phone' => '0123456789', 'nationality' => 'MY']]],
    'a wrong email' => ['guest.email', ['guest' => ['name' => 'A', 'email' => 'nope', 'phone' => '0123456789', 'nationality' => 'MY']]],
    'a wrong phone' => ['guest.phone', ['guest' => ['name' => 'A', 'email' => 'a@example.com', 'phone' => '12', 'nationality' => 'MY']]],
    'no nationality' => ['guest.nationality', ['guest' => ['name' => 'A', 'email' => 'a@example.com', 'phone' => '0123456789']]],
    'no lines' => ['lines', ['lines' => []]],
    'departure before arrival' => ['departure', ['departure' => '2026-10-19']],
]);

it('needs the guest IP from the microsite, but not from an API only client', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    postHold($setup, headers: [])->assertUnprocessable()->assertJsonPath('error.code', 'guest_ip_required');
    postHold($setup, headers: ['X-Guest-IP' => 'not-an-address'])->assertUnprocessable()->assertJsonPath('error.code', 'guest_ip_required');

    $setup['site']->forceFill(['mode' => 'api-only'])->save();
    BookingClient::factory()->apiOnly($setup['property'])->withToken('est_live_apionlyclientaaaaaaaaaaaaaaaaaaaaaaaa')->create();

    postHold($setup, headers: [], token: 'est_live_apionlyclientaaaaaaaaaaaaaaaaaaaaaaaa')->assertCreated();
    expect(BookingHold::query()->sole()->guest_ip_hash)->toBeNull();
});

it('caps the open rooms of a property at half its rooms and beds', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $bed = ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1];

    // 2 rooms and 3 beds: half is 3 lines.
    postHold($setup, ['total' => '280.00', 'lines' => [holdLine($setup), $bed]])->assertCreated();
    postHold($setup, ['total' => '80.00', 'lines' => [$bed]], ['X-Guest-IP' => '203.0.113.11'])->assertCreated();
    postHold($setup, ['total' => '80.00', 'lines' => [$bed]], ['X-Guest-IP' => '203.0.113.12'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'hold_limit_reached');
});

it('lets a small property hold two rooms', function () {
    $setup = bookingApiProperty();
    Room::query()->where('room_type_id', $setup['dorm']->id)->update(['archived_at' => now()]);
    bookingMicrositeClient();

    postHold($setup, ['total' => '400.00', 'lines' => [holdLine($setup), holdLine($setup)]])->assertCreated();
});

it('caps the open holds of one guest IP', function () {
    $setup = bookingApiProperty();
    Room::factory()->count(6)->for($setup['property'])->create(['room_type_id' => $setup['roomType']->id]);
    bookingMicrositeClient();

    foreach (range(1, 3) as $hold) {
        postHold($setup)->assertCreated();
    }

    postHold($setup)->assertUnprocessable()->assertJsonPath('error.code', 'hold_limit_reached');
    postHold($setup, headers: ['X-Guest-IP' => '203.0.113.11'])->assertCreated();
});

it('makes one hold for a repeated Idempotency-Key', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $headers = ['X-Guest-IP' => BOOKING_HOLD_IP, 'Idempotency-Key' => 'checkout-1'];

    $first = postHold($setup, headers: $headers)->assertCreated();
    $second = postHold($setup, headers: $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('token'))->toBe($first->json('token'))
        ->and(BookingHold::query()->count())->toBe(1);
});

it('limits how many holds one token makes for a property each minute', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();

    foreach (range(1, 30) as $attempt) {
        postHold($setup, ['total' => '1.00'])->assertUnprocessable();
    }

    postHold($setup, ['total' => '1.00'])->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');
});

it('closes checkout to a token without the ability and to properties out of reach', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient([BookingClient::ABILITY_CONTENT]);

    postHold($setup)->assertUnauthorized();
    postHold($setup, token: 'est_live_notarealtoken')->assertUnauthorized();
    bookingApiGet('holds/anything')->assertUnauthorized();
    test()->deleteJson('/api/booking/v1/holds/anything', [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])->assertUnauthorized();

    $setup['site']->forceFill(['published_at' => null])->save();
    BookingClient::query()->sole()->tokens()->delete();
    BookingClient::query()->sole()->update(['revoked_at' => now()]);
    bookingMicrositeClient();

    postHold($setup)->assertNotFound()->assertJsonPath('error.code', 'property_not_found');
});

it('shows a hold to the client that made it, and to no other', function () {
    $setup = bookingApiProperty();
    $client = bookingMicrositeClient();
    $hold = holdFor($setup, $client);
    $other = BookingClient::factory()->demo()->withToken('est_live_democlientaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();

    bookingApiGet('holds/'.$hold->token)->assertOk()->assertJsonPath('status', 'held')->assertJsonPath('token', $hold->token);
    bookingApiGet('holds/'.$hold->token, 'est_live_democlientaaaaaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound()->assertJsonPath('error.code', 'hold_not_found');
    test()->deleteJson('/api/booking/v1/holds/'.$hold->token, [], ['Authorization' => 'Bearer est_live_democlientaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertNotFound();

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Held)
        ->and($other->id)->not->toBe($client->id);
});

it('shows a Held hold past its expiry as Expired, before the job has run', function () {
    $setup = bookingApiProperty();
    $hold = holdFor($setup, bookingMicrositeClient(), 'pastExpiry');

    bookingApiGet('holds/'.$hold->token)->assertOk()->assertJsonPath('status', 'expired');
    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Held);
});

it('releases a Held hold at once, and only once', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    $token = postHold($setup)->json('token');

    expect(roomsFree($setup['roomType']))->toBe(1);

    Event::fake([InventoryChanged::class]);
    $delete = fn () => test()->deleteJson('/api/booking/v1/holds/'.$token, [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN]);

    $delete()->assertOk()->assertJsonPath('status', 'expired');

    expect(roomsFree($setup['roomType']))->toBe(2);
    Event::assertDispatchedTimes(InventoryChanged::class, 1);

    $delete()->assertUnprocessable()->assertJsonPath('error.code', 'hold_not_open');
    Event::assertDispatchedTimes(InventoryChanged::class, 1);
});

it('does not release a Paid hold', function () {
    $setup = bookingApiProperty();
    $hold = holdFor($setup, bookingMicrositeClient(), 'paid');

    test()->deleteJson('/api/booking/v1/holds/'.$hold->token, [], ['Authorization' => 'Bearer '.BOOKING_API_TOKEN])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'hold_not_open');

    expect($hold->refresh()->status)->toBe(BookingHoldStatus::Paid);
});

it('tells the channels which nights a new hold took', function () {
    $setup = bookingApiProperty();
    bookingMicrositeClient();
    Event::fake([InventoryChanged::class]);

    postHold($setup)->assertCreated();

    Event::assertDispatched(InventoryChanged::class, fn (InventoryChanged $event): bool => $event->propertyId === $setup['property']->id
        && $event->roomTypeId === $setup['roomType']->id
        && $event->dateFrom === '2026-10-20'
        && $event->dateTo === '2026-10-21');
});

it('counts Held and Paid holds in availability, and not expired or Confirmed ones', function (string $state, int $free) {
    $setup = bookingApiProperty();
    holdFor($setup, bookingMicrositeClient(), $state);

    expect(roomsFree($setup['roomType']))->toBe($free);
})->with([
    'Held' => ['held', 1],
    'Paid' => ['paid', 1],
    'past the expiry, before the job' => ['pastExpiry', 2],
    'Expired' => ['expired', 2],
    'Confirmed' => ['confirmed', 2],
]);

it('counts a hold only on its own nights, and only for its room type and property', function () {
    $setup = bookingApiProperty();
    holdFor($setup, bookingMicrositeClient());
    $otherSetup = bookingApiProperty('other-bay');
    $service = app(AvailabilityService::class);

    $nights = $service->nights($setup['roomType'], now()->setDate(2026, 10, 19)->toImmutable(), now()->setDate(2026, 10, 23)->toImmutable());

    expect(array_map(fn ($night): int => $night->held, $nights))->toBe([0, 1, 1, 0])
        ->and(array_map(fn ($night): int => $night->available, $nights))->toBe([2, 1, 1, 2])
        ->and(roomsFree($setup['dorm']))->toBe(3)
        ->and(roomsFree($otherSetup['roomType']))->toBe(2);
});

it('shows a held room as gone in the offers', function () {
    $setup = bookingApiProperty();
    holdFor($setup, bookingMicrositeClient());

    $offers = collect(bookingApiPost('properties/'.$setup['property']->code.'/offers', bookingApiStay())->assertOk()->json());

    expect($offers->firstWhere('room_type.id', $setup['roomType']->id)['rooms_left'])->toBe(1);
});

it('refuses a staff booking for the room a hold keeps', function () {
    $setup = bookingApiProperty();
    $client = bookingMicrositeClient();
    holdFor($setup, $client);
    holdFor($setup, $client);
    $reservation = Reservation::factory()->for($setup['property'])->create(['booker_guest_id' => $setup['guest']->id, 'booking_source_id' => $setup['phone']->id]);

    $result = app(AddUnit::class)($setup['user'], $reservation, new NewUnitData(
        $setup['roomType']->id,
        $setup['plan']->id,
        now()->setDate(2026, 10, 20)->toImmutable(),
        now()->setDate(2026, 10, 22)->toImmutable(),
        2,
    ));

    expect($result->refusal)->toBe(ReservationResult::REFUSAL_NOT_AVAILABLE);
});

it('expires only Held holds past their expiry, and tells the channels once', function () {
    $setup = bookingApiProperty();
    $client = bookingMicrositeClient();
    $old = holdFor($setup, $client, 'pastExpiry');
    $current = holdFor($setup, $client);
    $paid = BookingHold::factory()->for($setup['property'])->paid()->pastExpiry()->create(['booking_client_id' => $client->id]);
    Event::fake([InventoryChanged::class]);

    expect(app(ExpireBookingHolds::class)())->toBe(1)
        ->and($old->refresh()->status)->toBe(BookingHoldStatus::Expired)
        ->and($current->refresh()->status)->toBe(BookingHoldStatus::Held)
        ->and($paid->refresh()->status)->toBe(BookingHoldStatus::Paid)
        ->and(app(ExpireBookingHolds::class)())->toBe(0);

    Event::assertDispatchedTimes(InventoryChanged::class, 1);
});

it('purges guest details 30 days after a hold ended, and keeps the rest', function () {
    $setup = bookingApiProperty();
    $client = bookingMicrositeClient();
    $make = function (string $state, int $daysAgo) use ($setup, $client): BookingHold {
        $hold = holdFor($setup, $client, $state);
        $hold->forceFill(['guest_ip_hash' => 'abc', 'special_requests' => 'Quiet room', 'updated_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $hold;
    };
    $expired = $make('expired', 30);
    $confirmed = $make('confirmed', 31);
    $recent = $make('expired', 29);
    $paid = $make('paid', 40);

    expect(app(PurgeBookingHoldGuestDetails::class)())->toBe(2)
        ->and(app(PurgeBookingHoldGuestDetails::class)())->toBe(0);

    foreach ([$expired, $confirmed] as $hold) {
        $hold->refresh();
        expect([$hold->guest_name, $hold->guest_email, $hold->guest_phone, $hold->guest_nationality, $hold->special_requests, $hold->guest_ip_hash])->each->toBeNull();
    }

    expect($recent->refresh()->guest_email)->toBe('aisyah@example.com')
        ->and($paid->refresh()->guest_email)->toBe('aisyah@example.com');
});
