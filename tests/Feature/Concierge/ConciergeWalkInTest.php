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
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\WebhookEndpoint;
use App\Services\Kiosk\KioskStays;
use App\Services\Registration\RegistrationCardFieldRules;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

it('offers the walk-in link on the lobby only while the switch is on', function () {
    ['property' => $property] = conciergeWalkInSetup();

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('walkInEnabled', true));

    $property->settings->forceFill(['concierge_walk_in_enabled' => false])->save();
    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page->where('walkInEnabled', false));
    $this->get("/stay/{$property->code}/walk-in")->assertNotFound();
    $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 1, 'room_type_id' => 1])->assertNotFound();
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

it('books and holds the room, then sends the guest to the stay page to give an ID', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    $response = $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 2, 'room_type_id' => $type->id, 'total' => 1, 'property_id' => 999999])->assertRedirect();

    $hold = KioskWalkInHold::query()->sole();
    $stay = ReservationUnit::query()->findOrFail($hold->reservation_unit_id);
    expect($hold->kiosk_id)->toBeNull()
        ->and($hold->expires_at->toRfc3339String())->toBe(now()->addMinutes(10)->toRfc3339String())
        ->and($stay->status)->toBe(UnitStatus::Confirmed)
        ->and($stay->property_id)->toBe($property->id)
        ->and($stay->nights()->sum('amount'))->toBe('200.00')
        ->and($stay->reservation->booker->full_name)->toBe('')
        ->and(Folio::query()->where('reservation_unit_id', $stay->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'concierge.walk_in_booked')->sole()->properties->all())->toBe(['property_id' => $property->id])
        ->and(Activity::query()->where('event', 'kiosk.walk_in_booked')->count())->toBe(0);

    $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stay.step', 'identity')
        ->where('stay.guestFirstName', ''));
});

it('tells the guest when the room is gone, and books nothing', function () {
    ['property' => $property, 'type' => $type, 'room' => $room, 'unit' => $unit] = conciergeWalkInSetup();
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-14', 'room_id' => $room->id]);

    $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 1, 'room_type_id' => $type->id])->assertSessionHasErrors('room_type_id');

    expect(KioskWalkInHold::query()->count())->toBe(0);
});

it('stops taking walk-ins while too many holds are open', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();

    foreach (range(1, 10) as $number) {
        $other = kioskStayFor($property, "R-5{$number}");
        KioskWalkInHold::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $other['unit']->id, 'kiosk_id' => null, 'expires_at' => now()->addMinutes(5)]);
    }

    $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 1, 'room_type_id' => $type->id])->assertSessionHasErrors('room_type_id');

    expect(KioskWalkInHold::query()->count())->toBe(10);
});

it('releases an unpaid hold and tells the guest the hold ended, without letting the link write', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');
    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);

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
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 2, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');

    $this->post($location.'/identity', conciergeIdentityBody())->assertRedirect($location);

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

    $this->post("/stay/{$property->code}/walk-in", $body)->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->stay_type)->toBe(StayType::DayUse)
        ->and($stay->status)->toBe(UnitStatus::Confirmed)
        ->and($stay->dayUse->room_id)->toBe($room->id)
        ->and($stay->dayUse->hours)->toBe(2)
        ->and($stay->dayUse->amount)->toBe('60.00');

    $this->post("/stay/{$property->code}/walk-in", $body)->assertSessionHasErrors('room_type_id');
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
    $test->post("/stay/{$property->code}/walk-in", ['stay_type' => 'day_use', 'adults' => 1, 'start_time' => $start, 'hours' => $hours, 'room_type_id' => $type->id])->assertSessionHasNoErrors();

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
    $location = $test->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');
    $test->post($location.'/identity', conciergeIdentityBody($identity))->assertSessionHasNoErrors();
    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->latest('id')->firstOrFail()->reservation_unit_id);

    return ['stay' => $stay, 'guest' => app(KioskStays::class)->guest($stay)];
}

it('never joins a guest from the full ID form, because only the throttled returning check may', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'email' => null, 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);

    $guest = conciergeWalkInGivesIdentity($this, $property, $type, ['number' => 'A1234567', 'full_name' => 'Aisha R', 'date_of_birth' => '1990-05-17', 'email' => 'new@example.com'])['guest'];

    expect($guest->id)->not->toBe($returning->id)->and($returning->refresh()->email)->toBeNull()->and($returning->merged_into_id)->toBeNull();
});

it('does not join on the ID number when the date of birth differs, is missing, or the profile has none', function (?string $profileDob, ?string $typedDob) {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'date_of_birth' => $profileDob]);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);

    $guest = conciergeWalkInGivesIdentity($this, $property, $type, ['full_name' => 'Aisha R', 'date_of_birth' => $typedDob])['guest'];

    expect($guest->id)->not->toBe($returning->id)->and($returning->refresh()->merged_into_id)->toBeNull();
})->with([
    'different date' => ['1990-05-17', '1991-05-17'],
    'none typed' => ['1990-05-17', null],
    'none on profile' => [null, '1990-05-17'],
]);

it('recognises a returning walk-in from the ID number and date of birth alone, and skips the ID form', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'nationality_code' => 'MY', 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.step', 'identity')->where('stay.walkIn', true)->where('stay.identity', null));
    $this->post($location.'/identity/returning', ['number' => 'a 1234567', 'date_of_birth' => '1990-05-17'])->assertRedirect($location);

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect(app(KioskStays::class)->guest($stay)->id)->toBe($returning->id);
    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.walkIn', false)->whereNot('stay.step', 'identity'));
});

it('never joins a MyKad number, because the number already holds the date of birth', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'nationality_code' => 'MY', 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->create(['guest_id' => $returning->id, 'number' => '900517141234']);
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');

    $this->post($location.'/identity/returning', ['number' => '900517-14-1234', 'date_of_birth' => '1990-05-17'])->assertRedirect($location);

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect(app(KioskStays::class)->guest($stay)->id)->not->toBe($returning->id)->and($returning->refresh()->merged_into_id)->toBeNull();
});

it('changes nothing when the date of birth does not match, and limits the tries', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');
    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    $before = app(KioskStays::class)->guest($stay)->id;

    foreach (range(1, 5) as $try) {
        $this->post($location.'/identity/returning', ['number' => 'A1234567', 'date_of_birth' => '1991-0'.$try.'-17'])->assertRedirect($location);
    }
    $this->post($location.'/identity/returning', ['number' => 'A1234567', 'date_of_birth' => '1990-05-17'])->assertStatus(429);

    expect(app(KioskStays::class)->guest($stay)->id)->toBe($before)->and($returning->refresh()->merged_into_id)->toBeNull();
});

it('never joins a walk-in to a guest by a typed email, phone or name', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $byEmail = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Mei Ling', 'email' => 'mei@example.com', 'phone' => '+60123456789']);
    $sameName = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Lim Wei', 'email' => null, 'phone' => null]);

    $guest = conciergeWalkInGivesIdentity($this, $property, $type, ['number' => 'B1111111', 'full_name' => 'Lim Wei', 'email' => 'MEI@example.com', 'phone' => '012-345 6789'])['guest'];

    expect($guest->id)->not->toBe($byEmail->id)->and($guest->id)->not->toBe($sameName->id)
        ->and($guest->email)->toBe('mei@example.com')
        ->and($byEmail->refresh()->merged_into_id)->toBeNull();
});

it('does not show a returning guest\'s details back to the walk-in', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $returning = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Binti Rahman', 'nationality_code' => null, 'date_of_birth' => '1990-05-17']);
    GuestIdentityDocument::factory()->passport()->create(['guest_id' => $returning->id, 'number' => 'A1234567']);
    $this->mock(RegistrationCardFieldRules::class, fn ($mock) => $mock->shouldReceive('nationalityIsNeededForTax')->andReturn(true));
    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');

    $this->post($location.'/identity', conciergeIdentityBody(['nationality' => '', 'date_of_birth' => '1990-05-17']))->assertSessionHasNoErrors();

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.step', 'identity')->where('stay.identity', null));
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

it('asks every guest for a phone and an email on the ID step', function () {
    ['unit' => $unit] = conciergeWalkInSetup();
    $token = basename(app(IssueBookingPreCheckInToken::class)($unit));

    $this->post("/c/{$token}/identity", conciergeIdentityBody(['email' => '', 'phone' => '']))
        ->assertSessionHasErrors(['email', 'phone']);
});

it('gives a walk-in the room that has a door lock linked, before the first free room', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    $locked = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '102']);
    DoorLock::factory()->create(['property_id' => $property->id, 'room_id' => $locked->id]);

    $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->nights()->sole()->room_id)->toBe($locked->id);
});

it('holds the room that has a door lock for a day use walk-in', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    conciergeDayUsePlan($property, $type);
    $locked = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '102']);
    DoorLock::factory()->create(['property_id' => $property->id, 'room_id' => $locked->id]);

    $this->post("/stay/{$property->code}/walk-in", ['stay_type' => 'day_use', 'adults' => 1, 'start_time' => '20:30', 'hours' => 2, 'room_type_id' => $type->id])->assertRedirect();

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

    $this->post("/stay/{$property->code}/walk-in", $body)->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->dayUse->room_id)->toBe($room->id);

    $this->get("/stay/{$property->code}/walk-in?stay_type=day_use&adults=1&start_time=20:30&hours=2")->assertInertia(fn (Assert $page) => $page->has('offers', 0));
});

it('sends booking.created for a walk-in only once its hold is paid, with the booker details, and only once', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    Queue::fake();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated])->create();
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'transaction_code_id' => TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Payment])->id, 'name' => 'Cash', 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    $created = fn (): Collection => Queue::pushed(BroadcastWebhookJob::class)->filter(fn (BroadcastWebhookJob $job): bool => $job->event === WebhookEvent::BookingCreated);

    $location = $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->headers->get('Location');
    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    $this->post($location.'/identity', conciergeIdentityBody(['email' => 'aisha@example.com']))->assertSessionHasNoErrors();

    expect($created())->toHaveCount(0);

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

it('sends no booking.created for a walk-in hold that nobody pays', function () {
    ['property' => $property, 'type' => $type] = conciergeWalkInSetup();
    Queue::fake();
    WebhookEndpoint::factory()->forProperty($property)->hearing([WebhookEvent::BookingCreated, WebhookEvent::BookingCancelled])->create();

    $this->post("/stay/{$property->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $type->id])->assertRedirect();
    $this->travelTo(now()->addMinutes(11));
    ReleaseExpiredKioskHolds::dispatchSync();

    Queue::assertNotPushed(BroadcastWebhookJob::class);
});
