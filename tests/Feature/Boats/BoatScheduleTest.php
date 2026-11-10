<?php

use App\Actions\Boats\AddExtraSailing;
use App\Actions\Boats\AddSailingBoat;
use App\Actions\Boats\CancelDepartureDate;
use App\Actions\Boats\MoveBoatTrips;
use App\Actions\Boats\SaveSailing;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Boats\UpdateBoatDeparture;
use App\Data\Boats\BoatTripData;
use App\Data\Boats\SailingData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\UnitStatus;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Boats\BoatBoard;
use App\Services\Boats\BoatSeats;
use App\Services\Boats\DateSailings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/BoatSailingHelpers.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/** The body the Change time Dialog sends: the sailing's boat and crew as they are, and both times. */
function timeBody(array $setup, string $leaves, string $arrives, ?int $sailingId = null): array
{
    return [
        'boat_departure_id' => $setup['departure']->id,
        'boat_sailing_id' => $sailingId,
        'date' => '2026-10-20',
        'boat_id' => $setup['boat']->id,
        'in_charge_id' => $setup['din']->id,
        'assistant_ids' => [$setup['razak']->id],
        'sets_times' => true,
        'leaves_at' => $leaves,
        'arrives_at' => $arrives,
    ];
}

function scheduleTimes(array $setup, string $leaves, string $arrives): SailingData
{
    return new SailingData(boatId: $setup['boat']->id, inChargeId: $setup['din']->id, assistantIds: [$setup['razak']->id], setsTimes: true, leavesAt: $leaves, arrivesAt: $arrives);
}

test('Change time moves the unit times, a checked-in guest keeps their time, and the board says changed', function () {
    $setup = sailingSetup();
    $waiting = sailingTrip($setup, $setup['departure']);
    $arrived = sailingTrip($setup, $setup['departure']);
    $arrived->reservationUnit->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->put(route('boats.sailings.save', $code), timeBody($setup, '09:15', '10:00'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Time saved.']);

    expect($waiting->reservationUnit->refresh()->arrival_time)->toBe('10:00:00')
        ->and($arrived->reservationUnit->refresh()->arrival_time)->toBe('09:45:00')
        ->and(BoatSailing::query()->sole()->leaves_at)->toBe('09:15:00')
        ->and(Activity::query()->where('event', 'boat_sailing.saved')->count())->toBe(1);

    $this->get(route('boats.index', ['property' => $code, 'date' => '2026-10-20']))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.times', '09:15 Kuala Besut jetty → 10:00 (changed)'));
});

test('each boat of a departure has its own time, and a timetable change skips a trip whose sailing has its own time', function () {
    $setup = sailingSetup();
    $onMain = sailingTrip($setup, $setup['departure']);
    $second = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $onSecond = sailingTrip($setup, $setup['departure']);
    $onSecond->update(['boat_sailing_id' => $second->id]);

    $result = app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, setsTimes: true, leavesAt: '09:30', arrivesAt: '10:15'), $second);

    expect($result->isRefused())->toBeFalse()
        ->and($onSecond->reservationUnit->refresh()->arrival_time)->toBe('10:15:00')
        ->and($onMain->reservationUnit->refresh()->arrival_time)->toBe('09:45:00');

    app(UpdateBoatDeparture::class)($setup['owner'], $setup['departure'], [
        'direction' => 'in', 'leaves_at' => '09:05', 'arrives_at' => '09:50', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20, 'charge_item_id' => null, 'default_boat_id' => $setup['boat']->id, 'active' => true,
    ]);

    expect($onSecond->reservationUnit->refresh()->arrival_time)->toBe('10:15:00')
        ->and($onMain->reservationUnit->refresh()->arrival_time)->toBe('09:50:00');
});

test('a trip set on a date with a changed main time takes that time', function () {
    $setup = sailingSetup();
    app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], scheduleTimes($setup, '09:15', '10:00'));
    $trip = sailingTrip($setup, $setup['departure']);

    expect($trip->reservationUnit->refresh()->arrival_time)->toBe('10:00:00');
});

test('Cancel trip is refused while guests are booked, then cancels an empty date and Undo restores it', function () {
    $setup = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $code = $setup['property']->code;
    $url = route('boats.departures.cancel', [$code, $setup['departure']->id]);
    $this->actingAs($setup['owner']);

    $this->put($url, ['date' => '2026-10-20', 'cancelled' => true])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Move the 2 guests first.']);
    expect(BoatSailing::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'boat_sailing.cancelled')->count())->toBe(0);

    $this->get(route('boats.index', ['property' => $code, 'date' => '2026-10-20']))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.departureGuests', 2)->where('groups.0.cancelled', false));

    $trip->delete();

    $this->put($url, ['date' => '2026-10-20', 'cancelled' => true])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Trip cancelled.']);
    $sailing = BoatSailing::query()->sole();

    expect($sailing->cancelled_at)->not->toBeNull()
        ->and($sailing->cancelled_by)->toBe($setup['owner']->id)
        ->and($sailing->boat_id)->toBe($setup['boat']->id)
        ->and(Activity::query()->where('event', 'boat_sailing.cancelled')->count())->toBe(1);

    // A repeat changes nothing and logs nothing.
    app(CancelDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date']);
    expect(Activity::query()->where('event', 'boat_sailing.cancelled')->count())->toBe(1);

    $this->get(route('boats.index', ['property' => $code, 'date' => '2026-10-20']))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.cancelled', true));

    $this->put($url, ['date' => '2026-10-20', 'cancelled' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Trip restored.']);

    expect($sailing->refresh()->cancelled_at)->toBeNull()
        ->and($sailing->cancelled_by)->toBeNull()
        ->and(Activity::query()->where('event', 'boat_sailing.restored')->count())->toBe(1);
});

test('a cancelled departure leaves the seats picker for that date, is refused by SetBoatTrip and is not counted', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    app(CancelDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date']);
    $seatsUrl = route('reservations.boat-seats', ['property' => $code, 'direction' => 'in', 'date' => '2026-10-20']);

    $this->actingAs($setup['owner'])->getJson($seatsUrl)
        ->assertOk()
        ->assertJsonPath('cancelled', [$setup['departure']->id])
        ->assertJsonMissingPath('seats.'.$setup['departure']->id)
        ->assertJsonMissingPath('limits.'.$setup['departure']->id)
        ->assertJsonPath('seats.'.$setup['other']->id, 0);

    // Another date is not affected.
    $this->getJson(route('reservations.boat-seats', ['property' => $code, 'direction' => 'in', 'date' => '2026-10-21']))
        ->assertJsonPath('cancelled', [])
        ->assertJsonPath('seats.'.$setup['departure']->id, 0);

    $unit = sailingUnit($setup['property']);
    $result = app(SetBoatTrip::class)($setup['owner'], $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $setup['departure']->id));

    expect($result->refusal)->toBe('departure_cancelled')
        ->and($result->detail)->toBe('The 09:00 boat in does not run on 20 Oct.')
        ->and($unit->boatTrips()->count())->toBe(0);

    $seats = app(BoatSeats::class);
    $ids = [$setup['departure']->id, $setup['other']->id];

    expect($seats->forDate(BoatDirection::In, $setup['date'], $ids))->toBe([$setup['other']->id => 0])
        ->and($seats->limitsForDate($setup['property'], $setup['date'], $ids))->toBe([$setup['other']->id => 20])
        ->and($seats->cancelledForDate($ids, $setup['date']))->toBe([$setup['departure']->id]);

    // After Undo the departure is back.
    app(CancelDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date'], false);

    expect(app(SetBoatTrip::class)($setup['owner'], $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $setup['departure']->id))->isRefused())->toBeFalse()
        ->and($seats->forDate(BoatDirection::In, $setup['date'], $ids))->toBe([$setup['departure']->id => 2, $setup['other']->id => 0]);
});

test('the reservation boat Dialog shows the refusal on the field', function () {
    $setup = sailingSetup();
    app(CancelDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date']);
    $unit = sailingUnit($setup['property']);

    $this->actingAs($setup['owner'])
        ->put(route('reservations.units.boat-trips.set', ['property' => $setup['property']->code, 'reservation' => $unit->reservation_id, 'unit' => $unit->id, 'direction' => 'in']), ['mode' => 'scheduled', 'boat_departure_id' => $setup['departure']->id])
        ->assertSessionHasErrors(['boat_departure_id' => 'The 09:00 boat in does not run on 20 Oct.']);
});

test('an extra trip is added, guests Move to it keeping the fare and taking its time, and it is deleted when empty', function () {
    $setup = sailingSetup();
    $fare = sailingFare($setup['property'], '35.00');
    $setup['departure']->update(['charge_item_id' => $fare->id]);
    $trip = sailingTrip($setup, $setup['departure']);
    $rowsBefore = UnitAddOn::query()->where('reservation_unit_id', $trip->reservation_unit_id)->pluck('id')->all();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);

    $this->post(route('boats.sailings.store', $code), [
        'date' => '2026-10-20', 'direction' => 'in', 'leaves_at' => '13:00', 'arrives_at' => '13:45', 'pickup_point' => 'Jeti Kuala Besut',
        'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id, 'assistant_ids' => [],
    ])->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Extra trip added.']);

    $extra = BoatSailing::query()->whereNull('boat_departure_id')->sole();

    expect($extra->direction)->toBe(BoatDirection::In)
        ->and($extra->leaves_at)->toBe('13:00:00')
        ->and($extra->pickup_point)->toBe('Jeti Kuala Besut')
        ->and(Activity::query()->where('event', 'boat_sailing.extra_added')->count())->toBe(1);

    $groups = app(BoatBoard::class)->groups($setup['property'], $setup['date'], 'in');
    $group = collect($groups)->firstWhere('sailingId', $extra->id);

    expect($group->isExtra)->toBeTrue()
        ->and($group->departureId)->toBeNull()
        ->and($group->seats)->toBe(8)
        ->and($group->canRemove)->toBeTrue();

    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$trip->id], 'boat_sailing_id' => $extra->id])->assertSessionHasNoErrors();
    $trip->refresh();

    expect($trip->boat_sailing_id)->toBe($extra->id)
        ->and($trip->boat_departure_id)->toBe($setup['departure']->id)
        ->and($trip->reservationUnit->arrival_time)->toBe('13:45:00')
        ->and(UnitAddOn::query()->where('reservation_unit_id', $trip->reservation_unit_id)->pluck('id')->all())->toBe($rowsBefore);

    $groups = collect(app(BoatBoard::class)->groups($setup['property'], $setup['date'], 'in'));

    expect($groups->firstWhere('sailingId', $extra->id)->taken)->toBe(2)
        ->and($groups->firstWhere('sailingId', $extra->id)->canRemove)->toBeFalse();

    $this->delete(route('boats.sailings.destroy', [$code, $extra->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Move the 2 guests first.']);
    expect(BoatSailing::query()->whereKey($extra->id)->exists())->toBeTrue();

    // Back to the main sailing, then the empty extra trip can go.
    app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$trip->id], null, $setup['departure']->id);
    expect($trip->refresh()->boat_sailing_id)->toBeNull()
        ->and($trip->reservationUnit->arrival_time)->toBe('09:45:00');

    $this->delete(route('boats.sailings.destroy', [$code, $extra->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Extra trip deleted.']);
    expect(BoatSailing::query()->whereKey($extra->id)->exists())->toBeFalse();
});

test('an extra trip needs a direction and times in order, and the manifest names its group', function () {
    $setup = sailingSetup();
    $add = fn (SailingData $data) => app(AddExtraSailing::class)($setup['owner'], $setup['property'], $setup['date'], $data);

    expect($add(new SailingData(setsTimes: true, leavesAt: '13:00', arrivesAt: '12:00', direction: BoatDirection::In))->refusal)->toBe('times_invalid')
        ->and($add(new SailingData(setsTimes: true, leavesAt: '13:00', arrivesAt: '13:45'))->refusal)->toBe('times_invalid')
        ->and($add(new SailingData(boatId: $setup['spare']->id, setsTimes: true, leavesAt: '13:00', arrivesAt: '13:45', direction: BoatDirection::In, assistantIds: [$setup['razak']->id]))->refusal)->toBe('in_charge_required')
        ->and(BoatSailing::query()->count())->toBe(0);

    $extra = $add(new SailingData(setsTimes: true, leavesAt: '13:00', arrivesAt: '13:45', direction: BoatDirection::In))->sailing;
    $view = app(DateSailings::class)->extras($setup['property'], $setup['date']);

    expect($view)->toHaveCount(1)
        ->and($view[0]->seats)->toBeNull()
        ->and($view[0]->isExtra())->toBeTrue()
        ->and($view[0]->key())->toBe('sailing-'.$extra->id);
});

test('past dates are closed for time, cancel and extra trips', function () {
    $setup = sailingSetup();
    $past = CarbonImmutable::parse('2026-10-09');
    $code = $setup['property']->code;

    expect(app(SaveSailing::class)($setup['owner'], $setup['departure'], $past, scheduleTimes($setup, '09:15', '10:00'))->refusal)->toBe('date_closed')
        ->and(app(CancelDepartureDate::class)($setup['owner'], $setup['departure'], $past)->refusal)->toBe('date_closed')
        ->and(app(AddExtraSailing::class)($setup['owner'], $setup['property'], $past, new SailingData(setsTimes: true, leavesAt: '13:00', arrivesAt: '13:45', direction: BoatDirection::In))->refusal)->toBe('date_closed')
        ->and(BoatSailing::query()->count())->toBe(0);

    $this->actingAs($setup['owner'])->put(route('boats.departures.cancel', [$code, $setup['departure']->id]), ['date' => '2026-10-09', 'cancelled' => true])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This date is closed. Past dates are read only.']);
});

test('only users who manage reservations change the schedule, and another property gives a 404', function () {
    $setup = sailingSetup();
    $foreign = sailingSetup();
    $code = $setup['property']->code;
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($viewer)->put(route('boats.departures.cancel', [$code, $setup['departure']->id]), ['date' => '2026-10-20', 'cancelled' => true])->assertForbidden();
    $this->post(route('boats.sailings.store', $code), ['date' => '2026-10-20', 'direction' => 'in', 'leaves_at' => '13:00', 'arrives_at' => '13:45', 'assistant_ids' => []])->assertForbidden();

    $this->actingAs($setup['owner'])->put(route('boats.departures.cancel', [$code, $foreign['departure']->id]), ['date' => '2026-10-20', 'cancelled' => true])->assertNotFound();
    $extra = BoatSailing::factory()->create(['property_id' => $foreign['property']->id, 'boat_departure_id' => null, 'leaves_at' => '13:00:00', 'arrives_at' => '13:45:00']);
    $this->delete(route('boats.sailings.destroy', [$code, $extra->id]))->assertNotFound();

    expect(BoatSailing::query()->where('property_id', $setup['property']->id)->count())->toBe(0)
        ->and(BoatSailing::query()->whereKey($extra->id)->exists())->toBeTrue()
        ->and(BoatDeparture::query()->count())->toBeGreaterThan(0);
});

test('Change boat on the board puts the trip on the picked boat of the departure and back on the main one', function () {
    $setup = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $trip->forceFill(['ticked_at' => now(), 'ticked_by' => $setup['owner']->id])->save();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id, 'assistant_ids' => []])->assertSessionHasNoErrors();
    $added = BoatSailing::query()->where('boat_id', $setup['spare']->id)->sole();
    $setUrl = route('reservations.units.boat-trips.set', ['property' => $code, 'reservation' => $trip->reservationUnit->reservation_id, 'unit' => $trip->reservation_unit_id, 'direction' => 'in']);

    $this->get(route('boats.index', ['property' => $code, 'date' => '2026-10-20']), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'boats/index', 'X-Inertia-Partial-Data' => 'boatPicks', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])
        ->assertJsonPath('props.boatPicks.0.boat', 'MV Sand Bay 1')
        ->assertJsonPath('props.boatPicks.0.taken', 2)
        ->assertJsonPath('props.boatPicks.1.sailingId', $added->id)
        ->assertJsonPath('props.boatPicks.1.boat', 'MV Sand Bay 2')
        ->assertJsonPath('props.boatPicks.2.departureId', $setup['other']->id)
        ->assertJsonCount(3, 'props.boatPicks');

    $this->put($setUrl, ['mode' => 'scheduled', 'boat_departure_id' => $setup['departure']->id, 'boat_sailing_id' => $added->id])->assertSessionHasNoErrors();

    expect($trip->refresh()->boat_sailing_id)->toBe($added->id)
        ->and($trip->ticked_at)->toBeNull();

    $this->put($setUrl, ['mode' => 'scheduled', 'boat_departure_id' => $setup['departure']->id, 'boat_sailing_id' => null])->assertSessionHasNoErrors();

    expect($trip->refresh()->boat_sailing_id)->toBeNull();
});

test('a boat of another departure is refused, and a set without a boat keeps the boat and the note', function () {
    $setup = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id, 'assistant_ids' => []])->assertSessionHasNoErrors();
    $added = BoatSailing::query()->where('boat_id', $setup['spare']->id)->sole();
    $trip->forceFill(['boat_sailing_id' => $added->id, 'note' => 'Late ferry'])->save();
    $setUrl = route('reservations.units.boat-trips.set', ['property' => $code, 'reservation' => $trip->reservationUnit->reservation_id, 'unit' => $trip->reservation_unit_id, 'direction' => 'in']);

    $this->put($setUrl, ['mode' => 'scheduled', 'boat_departure_id' => $setup['other']->id, 'boat_sailing_id' => $added->id])->assertSessionHasErrors('boat_departure_id');

    expect($trip->refresh()->boat_departure_id)->toBe($setup['departure']->id);

    $this->put($setUrl, ['mode' => 'scheduled', 'boat_departure_id' => $setup['departure']->id])->assertSessionHasNoErrors();

    expect($trip->refresh()->boat_sailing_id)->toBe($added->id)
        ->and($trip->note)->toBe('Late ferry');
});
