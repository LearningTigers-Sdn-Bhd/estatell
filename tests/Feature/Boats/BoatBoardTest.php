<?php

require_once __DIR__.'/../Support/BoatSailingHelpers.php';

use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function boardUrl(array $setup, array $query = []): string
{
    return route('boats.index', ['property' => $setup['property']->code, 'date' => '2026-10-20', ...$query]);
}

/** A Boat out departure at 08:00 with a trip for a unit leaving on 20 Oct. */
function boardOutTrip(array $setup): UnitBoatTrip
{
    $out = BoatDeparture::factory()->for($setup['property'])->create(['direction' => BoatDirection::Out, 'leaves_at' => '08:00', 'arrives_at' => '08:30', 'pickup_point' => 'Resort jetty', 'seats' => 10]);
    $unit = sailingUnit($setup['property'], ['arrival_date' => '2026-10-17', 'departure_date' => '2026-10-20']);

    return UnitBoatTrip::factory()->out()->create(['property_id' => $setup['property']->id, 'reservation_unit_id' => $unit->id, 'boat_departure_id' => $out->id]);
}

function boardViewer(array $setup, array $permissions = ['reservations.view']): User
{
    return User::factory()->for($setup['property'])->withPermissions($permissions)->create();
}

test('the board lists the sailings in time order across both directions, then charter, then own boat, then boat not set', function () {
    $setup = sailingSetup();
    sailingTrip($setup, $setup['departure']);
    sailingTrip($setup, $setup['other']);
    boardOutTrip($setup);
    $charterUnit = sailingUnit($setup['property']);
    UnitBoatTrip::factory()->charter()->create(['property_id' => $setup['property']->id, 'reservation_unit_id' => $charterUnit->id]);
    $ownUnit = sailingUnit($setup['property'], ['arrival_time' => '10:30:00']);
    UnitBoatTrip::factory()->own()->create(['property_id' => $setup['property']->id, 'reservation_unit_id' => $ownUnit->id]);
    sailingUnit($setup['property'], ['arrival_date' => '2026-10-20']);

    $this->actingAs($setup['owner'])->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page
            ->component('boats/index')
            ->where('direction', 'both')
            ->where('isPast', false)
            ->where('groups.0.directionLabel', 'Boat out')
            ->where('groups.0.time', '08:00')
            ->where('groups.1.directionLabel', 'Boat in')
            ->where('groups.1.time', '09:00')
            ->where('groups.1.boat', 'MV Sand Bay 1')
            ->where('groups.1.inCharge', 'Din')
            ->where('groups.1.assistants', ['Razak'])
            ->where('groups.1.taken', 2)
            ->where('groups.1.seats', 12)
            ->where('groups.2.time', '11:00')
            ->where('groups.3.kind', 'charter')
            ->where('groups.3.title', 'Charter')
            ->where('groups.4.kind', 'own')
            ->where('groups.4.title', 'Own boat')
            ->where('groups.5.kind', 'not_set')
            ->has('groups', 6));

    $this->get(boardUrl($setup, ['direction' => 'in']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('direction', 'in')
            ->where('groups.0.time', '09:00')
            ->where('groups.1.time', '11:00')
            ->where('groups.2.kind', 'not_set')
            ->has('groups', 3));

    $this->get(boardUrl($setup, ['direction' => 'out']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.directionLabel', 'Boat out')
            ->where('groups.0.rows.0.direction', 'out')
            ->has('groups', 1));

    $this->get(boardUrl($setup, ['direction' => 'other']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('direction', 'other')
            ->where('groups.0.kind', 'charter')
            ->where('groups.0.rows.0.reservation_unit_id', $charterUnit->id)
            ->where('groups.1.kind', 'own')
            ->where('groups.1.rows.0.reservation_unit_id', $ownUnit->id)
            ->has('groups', 2));
});

test('a sailing with no guests still shows, an added boat has its own group and is not the main one, each boat and crew is sent, and the date defaults to the business date', function () {
    $setup = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $this->actingAs($setup['owner']);
    $this->post(route('boats.sailings.store', $setup['property']->code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id, 'assistant_ids' => []])->assertSessionHasNoErrors();

    $this->get(boardUrl($setup, ['direction' => 'in']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.boat', 'MV Sand Bay 1')
            ->where('groups.0.rows.0.key', $trip->id)
            ->where('groups.0.canRemove', false)
            ->where('groups.0.hasStored', true)
            ->where('groups.1.boat', 'MV Sand Bay 2')
            ->where('groups.1.rows', [])
            ->where('groups.1.seats', 8)
            ->where('groups.1.canRemove', true)
            ->where('groups.0.isMain', true)
            ->where('groups.0.departureBoats', 2)
            ->where('groups.1.isMain', false)
            ->where('groups.2.time', '11:00')
            ->where('groups.2.isMain', true)
            ->where('groups.2.departureBoats', 1)
            ->where('sailingCrews.0.time', '09:00')
            ->where('sailingCrews.0.boatId', $setup['boat']->id)
            ->where('sailingCrews.0.crewIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$setup['din']->id, $setup['razak']->id])->sort()->values()->all())
            ->where('sailingCrews.1.boatId', $setup['spare']->id)
            ->where('sailingCrews.1.crewIds', [$setup['razak']->id])
            ->has('sailingCrews', 3));

    $this->get(route('boats.index', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('date', '2026-10-10')->where('isPast', false));
    $this->get(route('boats.index', [$setup['property']->code, 'date' => '2026-10-09']))
        ->assertInertia(fn (Assert $page) => $page->where('isPast', true));
});

test('a property with no departures shows the empty state with a link to Boats settings for who can manage them', function () {
    $setup = sailingSetup();
    BoatDeparture::query()->delete();
    $viewer = boardViewer($setup);

    $this->actingAs($setup['owner'])->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page->where('hasDepartures', false)->has('groups', 0)->whereNot('settingsUrl', null));
    $this->actingAs($viewer)->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page->where('settingsUrl', null));
});

test('the sidebar entry, the page and every change follow the Boats feature and the permissions', function () {
    $setup = sailingSetup();
    $viewer = boardViewer($setup);
    $nobody = boardViewer($setup, ['guests.view']);
    $titles = fn ($response) => collect(data_get($response->viewData('page'), 'props.navigation.contexts'))
        ->flatMap(fn ($context) => collect($context['groups'])->flatMap(fn ($group) => $group['items']))
        ->pluck('title');

    expect($titles($this->actingAs($viewer)->get(route('dashboard', $setup['property']->code))))->toContain('Boat Trips')->not->toContain('Boat manifest')
        ->and($titles($this->actingAs($nobody)->get(route('dashboard', $setup['property']->code))))->not->toContain('Boat Trips');

    $this->actingAs($viewer)->get(boardUrl($setup))->assertOk();
    $this->actingAs($nobody)->get(boardUrl($setup))->assertForbidden();

    $body = ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'assistant_ids' => []];
    $this->actingAs($viewer)->put(route('boats.sailings.save', $setup['property']->code), $body)->assertForbidden();
    $this->post(route('boats.sailings.store', $setup['property']->code), $body)->assertForbidden();
    $this->post(route('boats.trips.tick', $setup['property']->code), ['trip_ids' => [1]])->assertForbidden();
    $this->post(route('boats.trips.move', $setup['property']->code), ['trip_ids' => [1], 'boat_sailing_id' => 1])->assertForbidden();
    $this->put(route('boats.departures.reset', [$setup['property']->code, $setup['departure']->id]), ['date' => '2026-10-20'])->assertForbidden();

    Feature::for($setup['property'])->deactivate(Boats::class);
    $this->actingAs($setup['owner'])->get(boardUrl($setup))->assertNotFound();
    expect($titles($this->get(route('dashboard', $setup['property']->code))))->not->toContain('Boat Trips');
});

test('a sailing, departure or trip of another property is a 404', function () {
    $setup = sailingSetup();
    $foreign = sailingSetup();
    $foreignTrip = sailingTrip($foreign, $foreign['departure']);
    $foreignSailing = BoatSailing::factory()->forDeparture($foreign['departure'])->create();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);

    $this->delete(route('boats.sailings.destroy', [$code, $foreignSailing->id]))->assertNotFound();
    $this->put(route('boats.departures.reset', [$code, $foreign['departure']->id]), ['date' => '2026-10-20'])->assertNotFound();
    $this->put(route('boats.sailings.save', $code), ['boat_departure_id' => $foreign['departure']->id, 'date' => '2026-10-20', 'assistant_ids' => []])->assertNotFound();
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $foreign['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['boat']->id, 'assistant_ids' => []])->assertNotFound();
    $this->post(route('boats.trips.tick', $code), ['trip_ids' => [$foreignTrip->id]])->assertNotFound();
    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$foreignTrip->id], 'boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20'])->assertNotFound();

    expect($foreignTrip->refresh()->ticked_at)->toBeNull()
        ->and(BoatSailing::query()->whereKey($foreignSailing->id)->exists())->toBeTrue();
});

test('Change boat and crew saves the main sailing through SaveSailing, and a refusal is an error toast that saves nothing', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $off = Boat::factory()->for($setup['property'])->create(['active' => false]);
    $this->actingAs($setup['owner']);

    $this->put(route('boats.sailings.save', $code), ['boat_departure_id' => $setup['departure']->id, 'boat_sailing_id' => null, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id, 'assistant_ids' => [$setup['din']->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat and crew saved.']);
    $sailing = BoatSailing::query()->where('boat_departure_id', $setup['departure']->id)->sole();

    expect($sailing->boat_id)->toBe($setup['spare']->id)
        ->and($sailing->crew->pluck('name')->all())->toBe(['Razak', 'Din']);

    $this->put(route('boats.sailings.save', $code), ['boat_departure_id' => $setup['departure']->id, 'boat_sailing_id' => $sailing->id, 'date' => '2026-10-20', 'boat_id' => $off->id, 'assistant_ids' => []])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pick an available boat and crew.']);
    $this->put(route('boats.sailings.save', $code), ['boat_departure_id' => $setup['departure']->id, 'boat_sailing_id' => $sailing->id, 'date' => '2026-10-20', 'boat_id' => $setup['boat']->id, 'in_charge_id' => '', 'assistant_ids' => [$setup['din']->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pick who is in charge first.']);

    expect($sailing->refresh()->boat_id)->toBe($setup['spare']->id);
});

test('Add boat adds a second sailing and needs a boat, Remove boat refuses guests, and Default goes back to the timetable', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $store = ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'assistant_ids' => []];

    $this->post(route('boats.sailings.store', $code), $store)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pick an available boat and crew.']);
    expect(BoatSailing::query()->count())->toBe(0);

    $this->post(route('boats.sailings.store', $code), [...$store, 'boat_id' => $setup['spare']->id, 'in_charge_id' => $setup['razak']->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat added.']);
    $added = BoatSailing::query()->where('boat_departure_id', $setup['departure']->id)->orderByDesc('id')->firstOrFail();
    expect(BoatSailing::query()->count())->toBe(2);

    $trip = sailingTrip($setup, $setup['departure']);
    $trip->update(['boat_sailing_id' => $added->id]);
    $this->delete(route('boats.sailings.destroy', [$code, $added->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Move the 2 guests first.']);
    $this->put(route('boats.departures.reset', [$code, $setup['departure']->id]), ['date' => '2026-10-20'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Move the 2 guests first.']);
    expect(BoatSailing::query()->count())->toBe(2);

    $trip->update(['boat_sailing_id' => null]);
    $this->delete(route('boats.sailings.destroy', [$code, $added->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat removed.']);
    expect(BoatSailing::query()->count())->toBe(1);

    $this->put(route('boats.departures.reset', [$code, $setup['departure']->id]), ['date' => '2026-10-20'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Back to the timetable.']);
    expect(BoatSailing::query()->count())->toBe(0);
});

test('Move to moves the ticked trips to a sailing or to a departure with no stored sailing', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'assistant_ids' => []]);
    $added = BoatSailing::query()->orderByDesc('id')->firstOrFail();
    $first = sailingTrip($setup, $setup['departure']);
    $second = sailingTrip($setup, $setup['departure']);
    $first->update(['ticked_at' => now()]);

    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$first->id, $second->id], 'boat_sailing_id' => $added->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved 2 rooms.']);

    expect($first->refresh()->boat_sailing_id)->toBe($added->id)
        ->and($first->ticked_at)->toBeNull()
        ->and($second->refresh()->boat_sailing_id)->toBe($added->id);

    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$first->id], 'boat_departure_id' => $setup['other']->id, 'date' => '2026-10-20'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Moved 1 room.']);

    expect($first->refresh()->boat_departure_id)->toBe($setup['other']->id);
});

test('Move to takes at most 200 trips, and one refused trip moves none', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $setup['spare']->id, 'assistant_ids' => []]);
    $added = BoatSailing::query()->orderByDesc('id')->firstOrFail();
    $good = sailingTrip($setup, $setup['departure']);
    $wrongDate = sailingTrip($setup, $setup['departure'], ['arrival_date' => '2026-10-21', 'departure_date' => '2026-10-23']);

    $this->post(route('boats.trips.move', $code), ['trip_ids' => range(1, 201), 'boat_sailing_id' => $added->id])->assertSessionHasErrors('trip_ids');
    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$good->id, $wrongDate->id], 'boat_sailing_id' => $added->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Guests can only move to a boat on the same date and direction.']);

    expect($good->refresh()->boat_sailing_id)->toBeNull()
        ->and($wrongDate->refresh()->boat_sailing_id)->toBeNull();

    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$good->id]])->assertSessionHasErrors(['boat_sailing_id', 'boat_departure_id']);
});

test('the bulk tick skips trips already ticked and logs only the trips it changed', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $already = sailingTrip($setup, $setup['departure']);
    $open = sailingTrip($setup, $setup['departure']);
    $before = now()->subHour();
    $already->forceFill(['ticked_at' => $before, 'ticked_by' => $setup['owner']->id])->save();

    $this->post(route('boats.trips.tick', $code), ['trip_ids' => [$already->id, $open->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 room updated.']);

    expect($open->refresh()->ticked_at)->not->toBeNull()
        ->and($open->ticked_by)->toBe($setup['owner']->id)
        ->and($already->refresh()->ticked_at->equalTo($before))->toBeTrue()
        ->and(Activity::query()->where('event', 'reservation.boat_trip_ticked')->count())->toBe(1);

    $this->post(route('boats.trips.tick', $code), ['trip_ids' => [$already->id, $open->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Nothing to change.']);
    $this->post(route('boats.trips.tick', $code), ['trip_ids' => [$open->id], 'ticked' => false]);

    expect($open->refresh()->ticked_at)->toBeNull();
});

test('a past date is read only for boats and crew, but the ticks still work', function () {
    $setup = sailingSetup();
    $code = $setup['property']->code;
    $this->actingAs($setup['owner']);
    $past = sailingUnit($setup['property'], ['arrival_date' => '2026-10-08', 'departure_date' => '2026-10-12']);
    $trip = UnitBoatTrip::factory()->create(['property_id' => $setup['property']->id, 'reservation_unit_id' => $past->id, 'boat_departure_id' => $setup['departure']->id]);

    $this->put(route('boats.sailings.save', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-08', 'boat_id' => $setup['spare']->id, 'assistant_ids' => []])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This date is closed. Past dates are read only.']);
    $this->post(route('boats.sailings.store', $code), ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-08', 'boat_id' => $setup['spare']->id, 'assistant_ids' => []])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This date is closed. Past dates are read only.']);
    $this->put(route('boats.departures.reset', [$code, $setup['departure']->id]), ['date' => '2026-10-08'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This date is closed. Past dates are read only.']);
    $this->post(route('boats.trips.move', $code), ['trip_ids' => [$trip->id], 'boat_departure_id' => $setup['other']->id, 'date' => '2026-10-08'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This date is closed. Past dates are read only.']);
    expect(BoatSailing::query()->count())->toBe(0);

    $this->post(route('boats.trips.tick', $code), ['trip_ids' => [$trip->id]]);

    expect($trip->refresh()->ticked_at)->not->toBeNull();
});

test('the Boat manifest page, its sidebar entry and its tick route are gone', function () {
    $setup = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->get("/p/{$code}/reports/boat-manifest?date=2026-10-20")->assertNotFound();
    $this->put("/p/{$code}/reports/boat-manifest/trips/{$trip->id}/tick", ['ticked' => true])->assertNotFound();

    expect(Route::has('reports.boat-manifest'))->toBeFalse()
        ->and(Route::has('reports.boat-manifest.tick'))->toBeFalse()
        ->and(Route::has('reports.boat-manifest.sailings'))->toBeFalse();
});

test('the single tick is boats.trips.set-tick, needs reservations.manage and keeps to the property', function () {
    $setup = sailingSetup();
    $foreign = sailingSetup();
    $trip = sailingTrip($setup, $setup['departure']);
    $foreignTrip = sailingTrip($foreign, $foreign['departure']);
    $code = $setup['property']->code;

    expect(route('boats.trips.set-tick', [$code, $trip->id], false))->toBe("/p/{$code}/boats/trips/{$trip->id}/tick");

    $this->actingAs(boardViewer($setup))->put(route('boats.trips.set-tick', [$code, $trip->id]), ['ticked' => true])->assertForbidden();
    expect($trip->refresh()->ticked_at)->toBeNull();

    $this->actingAs($setup['owner'])->put(route('boats.trips.set-tick', [$code, $trip->id]), ['ticked' => true])->assertRedirect();
    expect($trip->refresh()->ticked_at)->not->toBeNull();

    $this->put(route('boats.trips.set-tick', [$code, $trip->id]), ['ticked' => false])->assertRedirect();
    expect($trip->refresh()->ticked_at)->toBeNull();

    $this->put(route('boats.trips.set-tick', [$code, $foreignTrip->id]), ['ticked' => true])->assertNotFound();
    expect($foreignTrip->refresh()->ticked_at)->toBeNull();
});

test('the board sends the crew phone for the pickers, the manifest layout for Columns, and who may edit the columns', function () {
    $setup = sailingSetup();
    $setup['din']->update(['phone' => '012-111 2222']);
    $setup['razak']->update(['phone' => null]);
    $viewer = boardViewer($setup);

    $this->actingAs($setup['owner'])->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page
            ->where('crew', fn ($crew) => collect($crew)->pluck('phone', 'name')->all() === ['Din' => '012-111 2222', 'Razak' => null])
            ->where('layout.columns.0.key', 'departure')
            ->where('can.columns', true)
            ->where('can.manage', true)
            ->has('orientationOptions'));

    $this->actingAs($viewer)->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page->where('can.columns', false)->where('can.manage', false));
});

test('departures with no guests show their groups and empty Charter and Own boat groups, and Boat not set stays hidden', function () {
    $setup = sailingSetup();

    $this->actingAs($setup['owner'])->get(boardUrl($setup, ['direction' => 'in']))
        ->assertInertia(fn (Assert $page) => $page->has('groups', 2));

    $this->get(boardUrl($setup))
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups', 4)
            ->where('groups.0.kind', 'sailing')
            ->where('groups.0.rows', [])
            ->where('groups.1.kind', 'sailing')
            ->where('groups.1.rows', [])
            ->where('groups.2.kind', 'charter')
            ->where('groups.2.rows', [])
            ->where('groups.3.kind', 'own')
            ->where('groups.3.rows', []));
});
