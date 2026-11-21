<?php

use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\BookingSourceType;
use App\Enums\HousekeepingStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Enums\WebhookEvent;
use App\Features\Concierge;
use App\Jobs\BroadcastWebhookJob;
use App\Jobs\ReleaseExpiredKioskHolds;
use App\Models\DoorLock;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\KioskWalkInHold;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\WebhookEndpoint;
use App\Notifications\ConciergeWalkInConfirmed;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\RegistrationCardFieldRules;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

require_once __DIR__.'/../Support/KioskSetup.php';
require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * A property with the Concierge feature and its walk-in switch on, one free room (101) of Deluxe Double at RM 100 for
 * tonight, and the walk-in booking source. It has the fixture stay `R-100` of other guests, which holds no room.
 *
 * @return array{property: Property, type: RoomType, room: Room, unit: ReservationUnit}
 */
function conciergeWalkInSetup(): array
{
    $setup = kioskStaySetup();
    $property = $setup['property'];
    Feature::for($property)->activate(Concierge::class);
    $property->settings->forceFill(['concierge_walk_in_enabled' => true, 'security_deposit_amount' => '0.00', 'auto_assign_rooms' => true, 'card_required_for_check_in' => false])->save();

    $type = $setup['unit']->roomType;
    $type->forceFill(['max_adults' => 4, 'max_children' => 2])->save();
    $room = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '101']);
    $plan = RatePlan::factory()->for($property)->create();
    $type->ratePlans()->attach($plan);

    foreach (['2026-10-14', '2026-10-15'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $date, 'amount' => '100.00']);
    }

    BookingSourceFactory::systemFor($property->id, BookingSourceType::WalkIn);
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);

    return ['property' => $property, 'type' => $type, 'room' => $room, 'unit' => $setup['unit']];
}

/**
 * The room a walk-in picks: one adult and one night of the room type, unless said otherwise.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function conciergeWalkInRoom(RoomType $type, array $with = []): array
{
    return ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id, ...$with];
}

/**
 * Books a walk-in the way the page does: the room and the guest's details, sent together. Nothing is booked before this.
 *
 * @param  array<string, mixed>  $room
 * @param  array<string, mixed>  $identity
 */
function conciergeWalkInBook(TestCase $test, Property $property, array $room, array $identity = []): TestResponse
{
    return $test->post("/stay/{$property->code}/walk-in", [...$room, ...conciergeIdentityBody($identity)]);
}

/**
 * Books a walk-in and returns the stay page link and the stay.
 *
 * @param  array<string, mixed>  $identity
 * @return array{location: string, stay: ReservationUnit}
 */
function conciergeWalkInBooked(TestCase $test, Property $property, RoomType $type, array $identity = [], array $room = []): array
{
    $location = conciergeWalkInBook($test, $property, conciergeWalkInRoom($type, $room), $identity)->assertSessionHasNoErrors()->headers->get('Location');

    return ['location' => $location, 'stay' => ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->latest('id')->firstOrFail()->reservation_unit_id)];
}

/**
 * The room choice of a walk-in as the returning-guest check carries it, with the email or phone typed.
 *
 * @param  array<string, mixed>  $room
 * @return array<string, mixed>
 */
function conciergeReturningBody(string $contact, array $room): array
{
    return [...$room, 'contact' => $contact];
}

it('offers the walk-in link on the lobby only while the switch is on', function () {
    ['property' => $property] = conciergeWalkInSetup();

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('walkInEnabled', true));

    $property->settings->forceFill(['concierge_walk_in_enabled' => false])->save();
    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('walkInEnabled', false));
    $this->get("/stay/{$property->code}/walk-in")->assertNotFound();
    $this->get("/stay/{$property->code}/walk-in/details?adults=2&nights=1&room_type_id=1")->assertNotFound();
    conciergeWalkInBook($this, $property, ['adults' => 2, 'nights' => 1, 'room_type_id' => 1])->assertNotFound();
    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', ['adults' => 2, 'nights' => 1, 'room_type_id' => 1]))->assertNotFound();
});

it('shows the free rooms with the server price, for two adults and one night to begin with', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    $this->get("/stay/{$property->code}/walk-in")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/walk-in')
        ->where('adults', 2)
        ->where('nights', 1)
        ->has('offers', 1)
        ->where('offers.0.roomTypeId', $type->id)
        ->where('offers.0.name', 'Deluxe Double')
        ->where('offers.0.total', '100.00')
        ->where('offers.0.currency', 'MYR'));
});

it('refuses a search outside the limits', function (array $query) {
    ['property' => $property] = conciergeWalkInSetup();

    $this->get("/stay/{$property->code}/walk-in?".http_build_query($query))->assertSessionHasErrors();
})->with([[['adults' => 0, 'nights' => 1]], [['adults' => 2, 'nights' => 15]], [['adults' => 9, 'nights' => 1]]]);

it('books nothing while the guest looks at rooms or types their details, and books the room with their name when they send it', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $room = conciergeWalkInRoom($type, ['adults' => 2, 'nights' => 2]);

    $this->get("/stay/{$property->code}/walk-in?adults=2&nights=2")->assertOk();
    $this->get("/stay/{$property->code}/walk-in/details?".http_build_query($room))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/walk-in-details')
        ->where('offer.roomTypeId', $type->id)
        ->where('offer.name', 'Deluxe Double')
        ->where('offer.total', '200.00')
        ->where('booking.adults', 2)
        ->where('booking.nights', 2)
        ->where('booking.room_type_id', $type->id)
        ->has('countries')
        ->has('genders')
        ->has('identityKinds'));
    $before = ['holds' => KioskWalkInHold::query()->count(), 'guests' => Guest::query()->count(), 'reservations' => Reservation::query()->count()];

    expect(KioskWalkInHold::query()->count())->toBe($before['holds']);

    $response = conciergeWalkInBook($this, $property, [...$room, 'total' => 1, 'property_id' => 999999], ['full_name' => 'Aisha Rahman'])->assertSessionHasNoErrors()->assertRedirect();

    $hold = KioskWalkInHold::query()->sole();
    $stay = ReservationUnit::query()->findOrFail($hold->reservation_unit_id);
    expect($hold->kiosk_id)->toBeNull()
        ->and($hold->expires_at->toRfc3339String())->toBe(now()->addMinutes(10)->toRfc3339String())
        ->and($stay->status)->toBe(UnitStatus::Confirmed)
        ->and($stay->property_id)->toBe($property->id)
        ->and($stay->nights()->sum('amount'))->toBe('200.00')
        ->and($stay->reservation->booker->full_name)->toBe('Aisha Rahman')
        ->and(Guest::query()->count())->toBe($before['guests'] + 1)
        ->and(Reservation::query()->count())->toBe($before['reservations'] + 1)
        ->and(Folio::query()->where('reservation_unit_id', $stay->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'concierge.walk_in_booked')->sole()->properties->all())->toBe(['property_id' => $property->id])
        ->and(Activity::query()->where('event', 'kiosk.walk_in_booked')->count())->toBe(0);

    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stay.step', 'check_in')
        ->where('stay.guestFirstName', 'Aisha'));
});

it('never makes a booking without a name: a details form that is refused books nothing', function (array $identity) {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $counts = fn (): array => [KioskWalkInHold::query()->count(), Reservation::query()->count(), Guest::query()->count()];
    $before = $counts();

    conciergeWalkInBook($this, $property, conciergeWalkInRoom($type), $identity)->assertSessionHasErrors();

    expect($counts())->toBe($before);
})->with([
    'no name' => [['full_name' => '']],
    'no email' => [['email' => '']],
    'no phone' => [['phone' => '']],
    'a MyKad that is not 12 digits' => [['kind' => 'my_kad', 'number' => '12345']],
]);

it('asks for the nationality, and books nothing, when the property needs it and the guest left it empty', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $this->mock(RegistrationCardFieldRules::class, fn ($mock) => $mock->shouldReceive('nationalityIsNeededForTax')->andReturn(true));

    conciergeWalkInBook($this, $property, conciergeWalkInRoom($type), ['nationality' => ''])->assertSessionHasErrors('nationality');

    expect(KioskWalkInHold::query()->count())->toBe(0);
    $this->get("/stay/{$property->code}/walk-in/details?".http_build_query(conciergeWalkInRoom($type)))->assertInertia(fn (Assert $page) => $page->where('nationalityRequired', true));
});

it('tells the guest when the room is gone, and books nothing', function () {
    ['property' => $property, 'type' => $type, 'room' => $room, 'unit' => $unit] = conciergeWalkInSetup();
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-14', 'room_id' => $room->id]);
    $guests = Guest::query()->count();

    $this->get("/stay/{$property->code}/walk-in/details?".http_build_query(conciergeWalkInRoom($type)))->assertRedirect()->assertSessionHasErrors('room_type_id');
    conciergeWalkInBook($this, $property, conciergeWalkInRoom($type))->assertRedirectContains("/stay/{$property->code}/walk-in?")->assertSessionHasErrors('room_type_id');

    expect(KioskWalkInHold::query()->count())->toBe(0)->and(Guest::query()->count())->toBe($guests);
});

it('stops taking walk-ins while too many holds are open, and leaves no guest behind', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    foreach (range(1, 10) as $number) {
        $other = kioskStayFor($property, "R-5{$number}");
        KioskWalkInHold::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $other['unit']->id, 'kiosk_id' => null, 'expires_at' => now()->addMinutes(5)]);
    }
    $guests = Guest::query()->count();

    conciergeWalkInBook($this, $property, conciergeWalkInRoom($type))->assertSessionHasErrors('room_type_id');

    expect(KioskWalkInHold::query()->count())->toBe(10)->and(Guest::query()->count())->toBe($guests);
});

it('releases an unpaid hold and tells the guest the hold ended, without letting the link write', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    ['location' => $location, 'stay' => $stay] = conciergeWalkInBooked($this, $property, $type, room: ['adults' => 2]);

    $this->travelTo(now()->addMinutes(11));
    ReleaseExpiredKioskHolds::dispatchSync();

    expect($stay->refresh()->status)->toBe(UnitStatus::Cancelled);
    $this->get($location)->assertOk()->assertInertia(fn (Assert $page) => $page->where('stay.step', 'hold_expired'));
    $this->post($location.'/identity', conciergeIdentityBody())->assertNotFound();
    $this->post($location.'/check-in')->assertNotFound();
});

it('asks the walk-in for the deposit after the ID, the same as a guest with a booking', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $property->settings->forceFill(['security_deposit_amount' => '100.00'])->save();

    ['location' => $location] = conciergeWalkInBooked($this, $property, $type, room: ['adults' => 2]);

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.step', 'payment')->where('stay.shortfall', '100.00'));
});

/**
 * Adds a Day use plan (2 to 6 hours, no window) at RM 60 for 2h and RM 20 more an hour, for tonight, to the room type.
 */
function conciergeDayUsePlan(Property $property, RoomType $type): RatePlan
{
    $plan = RatePlan::factory()->for($property)->dayUse(2, 6)->create(['name' => 'Day use']);
    $type->ratePlans()->attach($plan);
    $rate = Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => '2026-10-14', 'amount' => null]);

    foreach (range(2, 6) as $hours) {
        $rate->hourPrices()->create(['hours' => $hours, 'amount' => number_format(60 + ($hours - 2) * 20, 2, '.', '')]);
    }

    return $plan;
}

it('offers day use only to a property that sells it', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    $this->get("/stay/{$property->code}/walk-in")->assertInertia(fn (Assert $page) => $page->where('canDayUse', false)->where('stayType', 'overnight'));

    conciergeDayUsePlan($property, $type);

    $this->get("/stay/{$property->code}/walk-in")->assertInertia(fn (Assert $page) => $page->where('canDayUse', true));
});

it('prices a day use from the plan and offers the room type', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=2&start_time=20:30&hours=2")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stayType', 'day_use')
        ->where('hours', 2)
        ->has('offers', 1)
        ->where('offers.0.roomTypeId', $type->id)
        ->where('offers.0.total', '60.00'));

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=2&start_time=20:30&hours=3")->assertInertia(fn (Assert $page) => $page->where('offers.0.total', '80.00'));
    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=2&start_time=20:30&hours=7")->assertInertia(fn (Assert $page) => $page->has('offers', 0));
});

it('books and holds a day use room, which the same hours then cannot book again', function () {
    ['property' => $property, 'type' => $type, 'room' => $room] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $body = ['stay_type' => 'day_use', 'adults' => 2, 'start_time' => '20:30', 'hours' => 2, 'room_type_id' => $type->id];

    conciergeWalkInBook($this, $property, $body)->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->stay_type)->toBe(StayType::DayUse)
        ->and($stay->status)->toBe(UnitStatus::Confirmed)
        ->and($stay->dayUse->room_id)->toBe($room->id)
        ->and($stay->dayUse->hours)->toBe(2)
        ->and($stay->dayUse->amount)->toBe('60.00');

    conciergeWalkInBook($this, $property, $body, ['email' => 'second@example.com'])->assertSessionHasErrors('room_type_id');
    expect(KioskWalkInHold::query()->count())->toBe(1);
});

it('opens a day use search on the half hour running now for two hours, and refuses one that already started', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=2")->assertSessionHasNoErrors()->assertInertia(fn (Assert $page) => $page
        ->where('startTime', '20:00')
        ->where('hours', 2)
        ->where('offers.0.total', '60.00'));
    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=2&start_time=00:00&hours=2")->assertInertia(fn (Assert $page) => $page->has('offers', 0));
});

/**
 * Hold a day use walk-in at a local time today and return the stay.
 */
function conciergeDayUseHeldAt(TestCase $test, Property $property, RoomType $type, string $now, string $start, int $hours): ReservationUnit
{
    $test->travelTo(CarbonImmutable::parse("2026-10-14 {$now}", $property->settings->time_zone));
    conciergeWalkInBook($test, $property, ['stay_type' => 'day_use', 'adults' => 1, 'start_time' => $start, 'hours' => $hours, 'room_type_id' => $type->id])->assertSessionHasNoErrors();

    return ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->latest('id')->firstOrFail()->reservation_unit_id);
}

it('sells a day use room at the exact time the last block ends, in the half hour running now', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $first = conciergeDayUseHeldAt($this, $property, $type, '18:00', '18:00', 2);

    $next = conciergeDayUseHeldAt($this, $property, $type, '20:02', '20:00', 2);

    expect($next->dayUse->room_id)->toBe($first->dayUse->room_id)
        ->and($next->dayUse->starts_at)->toBe('20:00:00')
        ->and($next->dayUse->ends_at)->toBe('22:00:00');
});

it('refuses a day use start before the half hour running now', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 20:02', $property->settings->time_zone));

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=1&start_time=19:30&hours=2")->assertInertia(fn (Assert $page) => $page->has('offers', 0));
    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=1&start_time=20:00&hours=2")->assertInertia(fn (Assert $page) => $page->has('offers', 1));
});

it('sells a room freed by an early day use check-out once housekeeping marks it Clean', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $first = conciergeDayUseHeldAt($this, $property, $type, '18:00', '18:00', 4);
    $first->forceFill(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now(), 'checked_out_at' => now()->addMinutes(100)])->save();
    $first->dayUse->forceFill(['vacated_at' => '19:40:00'])->save();
    Room::query()->whereKeyNot($first->dayUse->room_id)->where('room_type_id', $type->id)->update(['archived_at' => now()]);
    RoomStatus::query()->where('room_id', $first->dayUse->room_id)->update(['status' => HousekeepingStatus::Dirty]);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 20:02', $property->settings->time_zone));
    $search = "/stay/{$property->code}/walk-in?stay_type=day_use&adults=1&start_time=20:00&hours=2";

    $this->get($search)->assertInertia(fn (Assert $page) => $page->has('offers', 0));

    RoomStatus::query()->where('room_id', $first->dayUse->room_id)->update(['status' => HousekeepingStatus::Clean]);

    $this->get($search)->assertInertia(fn (Assert $page) => $page->has('offers', 1));
    expect(conciergeDayUseHeldAt($this, $property, $type, '20:02', '20:00', 2)->dayUse->room_id)->toBe($first->dayUse->room_id);
});

/**
 * A walk-in books a room and gives an ID, name, email and phone. Returns the stay and the guest it ended up with.
 *
 * @param  array<string, mixed>  $identity
 * @return array{stay: ReservationUnit, guest: Guest}
 */
function conciergeWalkInGivesIdentity(TestCase $test, Property $property, RoomType $type, array $identity): array
{
    $stay = conciergeWalkInBooked($test, $property, $type, $identity)['stay'];

    return ['stay' => $stay, 'guest' => app(KioskStays::class)->guest($stay)];
}

/**
 * Counts what a booking leaves behind, to show a refusal or a miss left nothing.
 *
 * @return array{holds: int, reservations: int, guests: int}
 */
function conciergeWalkInCounts(): array
{
    return ['holds' => KioskWalkInHold::query()->count(), 'reservations' => Reservation::query()->count(), 'guests' => Guest::query()->count()];
}

it('never joins a guest from the full details form, because only the throttled returning check may', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => null, 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);

    $guest = conciergeWalkInGivesIdentity($this, $property, $type, ['number' => 'A1234567', 'full_name' => 'Aisha R', 'date_of_birth' => '1990-05-17', 'email' => 'new@example.com'])['guest'];

    expect($guest->id)->not->toBe($returning->id)->and($returning->refresh()->email)->toBeNull()->and($returning->merged_into_id)->toBeNull();
});

it('recognises a returning walk-in from the email or phone alone, books the room for that guest, and skips the details form', function (string $typed) {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'nationality_code' => 'MY', 'date_of_birth' => '1990-05-17', 'email' => 'aisha@example.com', 'phone' => '+60123456789']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);
    $before = conciergeWalkInCounts();

    $response = $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody($typed, conciergeWalkInRoom($type)))->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect(app(KioskStays::class)->guest($stay)->id)->toBe($returning->id)
        ->and($stay->reservation->booker->id)->toBe($returning->id)
        ->and(conciergeWalkInCounts())->toBe([...$before, 'holds' => $before['holds'] + 1, 'reservations' => $before['reservations'] + 1])
        ->and(Activity::query()->where('event', 'concierge.returning_guest_joined')->sole()->subject_id)->toBe($returning->id);
    $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page->where('stay.guestFirstName', 'Aisha')->whereNot('stay.step', 'identity'));
})->with([
    'email, other case and spaces' => [' AISHA@Example.com '],
    'phone as typed locally' => ['012-345 6789'],
    'phone with the country code' => ['+60 12-345 6789'],
]);

it('joins a profile that has no ID saved yet, and asks for the ID alone without showing the profile', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com', 'phone' => null, 'date_of_birth' => '1990-05-17']);

    $response = $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', conciergeWalkInRoom($type)))->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect(app(KioskStays::class)->guest($stay)->id)->toBe($returning->id);
    $this->get($response->headers->get('Location'))->assertInertia(fn (Assert $page) => $page->where('stay.step', 'identity')
        ->where('stay.identity.hideKnown', true)
        ->where('stay.identity.hasName', true)
        ->where('stay.identity.hasEmail', true)
        ->where('stay.identity.hasPhone', false)
        ->where('stay.identity.fullName', null)
        ->where('stay.identity.hasDateOfBirth', false)
        ->where('stay.identity.documentNumberHint', null));
});

it('saves the ID of a returning walk-in without asking for the name, phone or email it already holds', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com', 'phone' => '+60123456789']);
    $location = $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', conciergeWalkInRoom($type)))->headers->get('Location');

    $this->post("{$location}/identity", ['kind' => 'passport', 'number' => 'A1234567'])->assertSessionHasNoErrors()->assertRedirect($location);

    $returning->refresh();
    expect($returning->documents()->count())->toBe(1)
        ->and($returning->full_name)->toBe('Aisha Binti Rahman')
        ->and($returning->email)->toBe('aisha@example.com')
        ->and($returning->phone)->toBe('+60123456789');
    $this->get($location)->assertInertia(fn (Assert $page) => $page->whereNot('stay.step', 'identity'));
});

it('still asks a returning walk-in for the phone and email its profile lacks', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com', 'phone' => null]);
    $location = $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', conciergeWalkInRoom($type)))->headers->get('Location');

    $this->post("{$location}/identity", ['kind' => 'passport', 'number' => 'A1234567'])->assertSessionHasErrors('phone')->assertSessionDoesntHaveErrors(['email', 'full_name']);
});

it('books nothing when no profile has the email or phone', function (string $typed) {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com', 'phone' => '+60123456789']);
    $before = conciergeWalkInCounts();

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody($typed, conciergeWalkInRoom($type)))->assertRedirect()->assertSessionHasNoErrors();

    expect(conciergeWalkInCounts())->toBe($before)->and($returning->refresh()->merged_into_id)->toBeNull();
})->with(['unknown email' => ['nobody@example.com'], 'unknown phone' => ['011-999 8888'], 'not a phone' => ['hello']]);

it('books nothing when two profiles share the phone, or the profile has no name', function (string $case) {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $first = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'phone' => '+60123456789']);
    $second = $case === 'shared'
        ? Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Rahman Bin Ali', 'phone' => '+60123456789'])
        : null;
    $case === 'unnamed' && $first->forceFill(['full_name' => ''])->save();
    $before = conciergeWalkInCounts();

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('012-345 6789', conciergeWalkInRoom($type)))->assertRedirect();

    expect(conciergeWalkInCounts())->toBe($before)->and($first->refresh()->merged_into_id)->toBeNull()->and($second?->refresh()->merged_into_id)->toBeNull();
})->with(['shared', 'unnamed']);

it('joins the duplicate with the most stays when profiles of one guest share the contact', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $stays = fn (Guest $guest, int $count) => collect(range(1, $count))->each(fn () => UnitGuest::factory()->create([
        'guest_id' => $guest->id,
        'reservation_unit_id' => ReservationUnit::factory()->create([
            'reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id,
            'status' => UnitStatus::CheckedOut,
            'checked_in_at' => now()->subDay(),
            'checked_out_at' => now(),
        ])->id,
    ]));
    $one = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Andy Yap', 'email' => 'andy@example.com']);
    $two = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Andy Yap', 'email' => 'andy@example.com']);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Andy test', 'email' => 'andy@example.com']);
    $stays($one, 1);
    $stays($two, 2);

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('andy@example.com', conciergeWalkInRoom($type)))->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect(app(KioskStays::class)->guest($stay)->id)->toBe($two->id);
});

it('books nothing when profiles with stays and different names share the contact', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    foreach (['Aisha Binti Rahman', 'Rahman Bin Ali'] as $name) {
        $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => $name, 'phone' => '+60123456789']);
        UnitGuest::factory()->create(['guest_id' => $guest->id, 'reservation_unit_id' => ReservationUnit::factory()->create([
            'reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id,
            'status' => UnitStatus::CheckedOut,
            'checked_in_at' => now()->subDay(),
            'checked_out_at' => now(),
        ])->id]);
    }
    $before = conciergeWalkInCounts();

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('012-345 6789', conciergeWalkInRoom($type)))->assertRedirect();

    expect(conciergeWalkInCounts())->toBe($before);
});

it('limits the tries to recognise a returning walk-in', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com']);
    $before = conciergeWalkInCounts();

    foreach (range(1, 5) as $try) {
        $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody("guess{$try}@example.com", conciergeWalkInRoom($type)))->assertRedirect();
    }
    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', conciergeWalkInRoom($type)))->assertStatus(429);

    expect(conciergeWalkInCounts())->toBe($before)->and($returning->refresh()->merged_into_id)->toBeNull();
});

it('tells a returning walk-in when the room is gone, and books nothing', function () {
    ['property' => $property, 'type' => $type, 'room' => $room, 'unit' => $unit] = conciergeWalkInSetup();
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => 'aisha@example.com']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-14', 'room_id' => $room->id]);
    $before = conciergeWalkInCounts();

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeReturningBody('aisha@example.com', conciergeWalkInRoom($type)))->assertSessionHasErrors('room_type_id');

    expect(conciergeWalkInCounts())->toBe($before);
});

it('asks for the contact and the room, and nothing else, to recognise a returning walk-in', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    $this->post("/stay/{$property->code}/walk-in/returning", conciergeWalkInRoom($type))->assertSessionHasErrors(['contact']);
    $this->post("/stay/{$property->code}/walk-in/returning", ['contact' => 'aisha@example.com'])->assertSessionHasErrors(['adults', 'room_type_id']);
});

it('never joins a walk-in to a guest from an email, phone or name typed on the full details form', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $byEmail = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Mei Ling', 'email' => 'mei@example.com', 'phone' => '+60123456789']);
    $sameName = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Lim Wei', 'email' => null, 'phone' => null]);

    $guest = conciergeWalkInGivesIdentity($this, $property, $type, ['number' => 'B1111111', 'full_name' => 'Lim Wei', 'email' => 'MEI@example.com', 'phone' => '012-345 6789'])['guest'];

    expect($guest->id)->not->toBe($byEmail->id)->and($guest->id)->not->toBe($sameName->id)
        ->and($guest->email)->toBe('mei@example.com')
        ->and($byEmail->refresh()->merged_into_id)->toBeNull();
});

it('leaves a booking made earlier with its own guest', function () {
    ['property' => $property, 'unit' => $unit] = conciergeWalkInSetup();
    $other = Guest::factory()->create(['property_id' => $property->id, 'email' => 'same@example.com']);
    $booked = app(KioskStays::class)->guest($unit);
    $booked->forceFill(['full_name' => 'Already Named'])->save();
    $token = basename(app(IssueBookingPreCheckInToken::class)($unit));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['email' => 'same@example.com']))->assertSessionHasNoErrors();

    expect(app(KioskStays::class)->guest($unit)->id)->toBe($booked->id)->and($other->refresh()->merged_into_id)->toBeNull();
});

it('asks for the phone and email on the ID step when the profile has neither', function () {
    ['unit' => $unit] = conciergeWalkInSetup();
    app(KioskStays::class)->guest($unit)->forceFill(['email' => null, 'phone' => null])->save();
    $token = basename(app(IssueBookingPreCheckInToken::class)($unit));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['email' => '', 'phone' => '']))
        ->assertSessionHasErrors(['email', 'phone']);
});

it('gives a walk-in the room that has a door lock linked, before the first free room', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $locked = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '102']);
    DoorLock::factory()->create(['property_id' => $property->id, 'room_id' => $locked->id]);

    conciergeWalkInBook($this, $property, conciergeWalkInRoom($type))->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->nights()->sole()->room_id)->toBe($locked->id);
});

it('holds the room that has a door lock for a day use walk-in', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $locked = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '102']);
    DoorLock::factory()->create(['property_id' => $property->id, 'room_id' => $locked->id]);

    conciergeWalkInBook($this, $property, ['stay_type' => 'day_use', 'adults' => 1, 'start_time' => '20:30', 'hours' => 2, 'room_type_id' => $type->id])->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->dayUse->room_id)->toBe($locked->id);
});

it('never offers or holds a Dirty room for a day use walk-in', function () {
    ['property' => $property, 'type' => $type, 'room' => $room] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $locked = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '102']);
    DoorLock::factory()->create(['property_id' => $property->id, 'room_id' => $locked->id]);
    RoomStatus::query()->where('room_id', $locked->id)->update(['status' => HousekeepingStatus::Dirty]);
    $body = ['stay_type' => 'day_use', 'adults' => 1, 'start_time' => '20:30', 'hours' => 2, 'room_type_id' => $type->id];

    conciergeWalkInBook($this, $property, $body)->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->dayUse->room_id)->toBe($room->id);

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=1&start_time=20:30&hours=2")->assertInertia(fn (Assert $page) => $page->has('offers', 0));
});

it('sends booking.created for a walk-in that owes nothing once the ID step is saved, with the booker details, and only once', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    Queue::fake();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated])->create();
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment])->id, 'name' => 'Cash', 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    $created = fn (): Collection => Queue::pushed(BroadcastWebhookJob::class)->filter(fn (BroadcastWebhookJob $job): bool => $job->event === WebhookEvent::BookingCreated);

    ['stay' => $stay] = conciergeWalkInBooked($this, $property, $type, ['email' => 'aisha@example.com']);

    expect($created())->toHaveCount(1);

    foreach (['60.00', '40.00'] as $amount) {
        $result = app(RecordPayment::class)(null, $stay->reservation, null, $stay, new NewPaymentData($cash->id, PaymentPurpose::Advance, $amount, null, (string) Str::uuid()));
        expect($result->refusal ?? null)->toBeNull();
    }

    expect($created())->toHaveCount(1);
    $job = $created()->first();
    $booker = app($job->event->payloadBuilder())->build($job->event, $stay->reservation->refresh(), $job->context)['booking']['booker'];
    expect($job->subjectId)->toBe($stay->reservation_id)
        ->and($booker['name'])->toBe('Aisha Rahman')
        ->and($booker['email'])->toBe('aisha@example.com')
        ->and(KioskWalkInHold::query()->sole()->announced_at)->not->toBeNull();
});

it('emails a walk-in that owes nothing the booking number and the check-in link once the ID step is saved, and only once', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    Notification::fake();
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment])->id, 'name' => 'Cash', 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);

    ['stay' => $stay] = conciergeWalkInBooked($this, $property, $type, ['email' => 'aisha@example.com']);

    Notification::assertSentOnDemandTimes(ConciergeWalkInConfirmed::class, 1);

    foreach (['60.00', '40.00'] as $amount) {
        app(RecordPayment::class)(null, $stay->reservation, null, $stay, new NewPaymentData($cash->id, PaymentPurpose::Advance, $amount, null, (string) Str::uuid()));
    }

    Notification::assertSentOnDemandTimes(ConciergeWalkInConfirmed::class, 1);
    Notification::assertSentOnDemand(ConciergeWalkInConfirmed::class, fn (ConciergeWalkInConfirmed $mail, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'aisha@example.com'
        && $mail->reference === $stay->reservation->reference
        && $mail->shortCode === $stay->reservation->short_code
        && str_contains($mail->stayUrl, '/c/'));
});

it('sends no booking.created for a walk-in hold that nobody pays', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $property->settings->forceFill(['security_deposit_amount' => '100.00'])->save();
    Queue::fake();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated, WebhookEvent::BookingCancelled])->create();

    conciergeWalkInBooked($this, $property, $type);
    $this->travelTo(now()->addMinutes(11));
    ReleaseExpiredKioskHolds::dispatchSync();

    Queue::assertNotPushed(BroadcastWebhookJob::class);
});
