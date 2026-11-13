<?php

use App\Enums\BookingSourceType;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Jobs\ReleaseExpiredKioskHolds;
use App\Models\Folio;
use App\Models\KioskWalkInHold;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use Database\Factories\BookingSourceFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

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
