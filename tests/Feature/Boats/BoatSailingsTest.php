<?php

use App\Actions\Boats\AddSailingBoat;
use App\Actions\Boats\MoveBoatTrips;
use App\Actions\Boats\RemoveSailing;
use App\Actions\Boats\ResetDepartureDate;
use App\Actions\Boats\SaveSailing;
use App\Actions\Boats\UpdateBoatDeparture;
use App\Data\Boats\SailingData;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\UnitAddOn;
use App\Services\Boats\BoatSeats;
use App\Services\Boats\DateSailings;
use App\Services\Boats\SailingLabels;
use App\Services\Reports\BoatManifestReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/BoatSailingHelpers.php';

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

test('a date with no rows runs as the timetable: the in-memory main sailing has the default boat and crew', function () {
    $setup = sailingSetup();
    $main = sailingMain($setup);

    expect(BoatSailing::query()->count())->toBe(0)
        ->and($main->sailing)->toBeNull()
        ->and($main->isMain)->toBeTrue()
        ->and($main->boat->name)->toBe('MV Sand Bay 1')
        ->and($main->inCharge()->name)->toBe('Din')
        ->and($main->assistants()->pluck('name')->all())->toBe(['Razak'])
        ->and([$main->leavesAt, $main->arrivesAt, $main->timeChanged, $main->seats])->toBe(['09:00', '09:45', false, 12])
        ->and(app(SailingLabels::class)->title($main, true))->toBe('Boat in · 09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 1 · Din (in charge), Razak');
});

test('trips with no sailing are on the main sailing, and a moved trip is on its own sailing', function () {
    $setup = sailingSetup();
    $plain = sailingTrip($setup, $setup['departure']);
    $moved = sailingTrip($setup, $setup['departure']);
    $added = ($a = app(AddSailingBoat::class))($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $moved->update(['boat_sailing_id' => $added->id]);

    $sailings = app(DateSailings::class)->forDate($setup['property'], $setup['date']);
    $service = app(DateSailings::class);
    [$main, $second] = $sailings[$setup['departure']->id];

    expect($service->sailingOf($plain->refresh(), $sailings)->key())->toBe($main->key())
        ->and($service->sailingOf($moved->refresh(), $sailings)->key())->toBe($second->key())
        ->and($service->tripsOn($main)->pluck('unit_boat_trips.id')->all())->toBe([$plain->id])
        ->and($service->tripsOn($second)->pluck('unit_boat_trips.id')->all())->toBe([$moved->id]);
});

test('the first change stores the main sailing with the copied boat and crew', function () {
    $setup = sailingSetup();

    $result = app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(
        boatId: $setup['spare']->id,
        inChargeId: $setup['din']->id,
        assistantIds: [$setup['razak']->id],
    ));
    $sailing = BoatSailing::query()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($sailing->boat_id)->toBe($setup['spare']->id)
        ->and($sailing->boat_departure_id)->toBe($setup['departure']->id)
        ->and($sailing->date->toDateString())->toBe('2026-10-20')
        ->and($sailing->crew->pluck('name')->all())->toBe(['Din', 'Razak'])
        ->and((bool) $sailing->crew->first()->pivot->in_charge)->toBeTrue()
        ->and(Activity::query()->where('event', 'boat_sailing.saved')->count())->toBe(1);

    // A later change of the timetable default does not touch the stored sailing.
    $setup['departure']->update(['default_boat_id' => $setup['boat']->id]);
    $setup['departure']->crew()->detach();

    expect(sailingMain($setup)->boat->name)->toBe('MV Sand Bay 2')
        ->and(sailingMain($setup)->crew)->toHaveCount(2);
});

test('only the crew changes: the boat is copied, and a save that changes nothing stores and logs nothing', function () {
    $setup = sailingSetup();
    $same = new SailingData(boatId: $setup['boat']->id, inChargeId: $setup['din']->id, assistantIds: [$setup['razak']->id]);

    expect(app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], $same)->isRefused())->toBeFalse()
        ->and(BoatSailing::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'boat_sailing.saved')->count())->toBe(0);

    app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['boat']->id, inChargeId: $setup['razak']->id));
    $sailing = BoatSailing::query()->sole();

    expect($sailing->boat_id)->toBe($setup['boat']->id)
        ->and($sailing->crew->pluck('name')->all())->toBe(['Razak'])
        ->and(app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['boat']->id, inChargeId: $setup['razak']->id))->sailing->is($sailing))->toBeTrue()
        ->and(Activity::query()->where('event', 'boat_sailing.saved')->count())->toBe(1);
});

test('adding a boat stores the main sailing first and the new sailing is the second', function () {
    $setup = sailingSetup();

    $result = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['razak']->id));
    $sailings = BoatSailing::query()->orderBy('id')->get();
    $views = app(DateSailings::class)->forDate($setup['property'], $setup['date'], [$setup['departure']->id])[$setup['departure']->id];

    expect($result->isRefused())->toBeFalse()
        ->and($sailings)->toHaveCount(2)
        ->and($sailings[0]->boat_id)->toBe($setup['boat']->id)
        ->and($sailings[0]->crew->pluck('name')->all())->toBe(['Din', 'Razak'])
        ->and($sailings[1]->is($result->sailing))->toBeTrue()
        ->and($sailings[1]->boat_id)->toBe($setup['spare']->id)
        ->and(array_map(fn ($view) => $view->isMain, $views))->toBe([true, false])
        ->and(Activity::query()->where('event', 'boat_sailing.boat_added')->count())->toBe(1);
});

test('an added boat is removed when it has no guests, and the main sailing is not removed', function () {
    $setup = sailingSetup();
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $main = BoatSailing::query()->orderBy('id')->first();

    expect(app(RemoveSailing::class)($setup['owner'], $main)->refusal)->toBe('sailing_is_main');

    $trip = sailingTrip($setup, $setup['departure']);
    $trip->update(['boat_sailing_id' => $added->id]);

    $refused = app(RemoveSailing::class)($setup['owner'], $added);

    expect($refused->refusal)->toBe('sailing_has_guests')
        ->and($refused->guests)->toBe(2)
        ->and(BoatSailing::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'boat_sailing.removed')->count())->toBe(0);

    $trip->update(['boat_sailing_id' => null]);

    expect(app(RemoveSailing::class)($setup['owner'], $added)->isRefused())->toBeFalse()
        ->and(BoatSailing::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'boat_sailing.removed')->count())->toBe(1);
});

test('Default deletes the stored sailings, and is refused while an added boat has guests', function () {
    $setup = sailingSetup();
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $trip = sailingTrip($setup, $setup['departure']);
    $trip->update(['boat_sailing_id' => $added->id]);

    $refused = app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date']);

    expect($refused->refusal)->toBe('sailing_has_guests')
        ->and(BoatSailing::query()->count())->toBe(2);

    $trip->update(['boat_sailing_id' => null]);

    expect(app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date'])->isRefused())->toBeFalse()
        ->and(BoatSailing::query()->count())->toBe(0)
        ->and(sailingMain($setup)->sailing)->toBeNull()
        ->and(Activity::query()->where('event', 'boat_sailing.reset')->count())->toBe(1)
        ->and(app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date'])->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'boat_sailing.reset')->count())->toBe(1);
});

test('a date before the business date is closed, and a boat or crew that is off or foreign is unavailable', function () {
    $setup = sailingSetup();
    $save = app(SaveSailing::class);
    $off = Boat::factory()->for($setup['property'])->create(['active' => false]);
    $foreign = Boat::factory()->create();
    $offCrew = BoatCrewMember::factory()->for($setup['property'])->inactive()->create();

    expect($save($setup['owner'], $setup['departure'], CarbonImmutable::parse('2026-10-09'), new SailingData(boatId: $setup['spare']->id))->refusal)->toBe('date_closed')
        ->and(app(AddSailingBoat::class)($setup['owner'], $setup['departure'], CarbonImmutable::parse('2026-10-09'), new SailingData(boatId: $setup['spare']->id))->refusal)->toBe('date_closed')
        ->and(app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], CarbonImmutable::parse('2026-10-09'))->refusal)->toBe('date_closed')
        ->and($save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $off->id))->refusal)->toBe('boat_unavailable')
        ->and($save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $foreign->id))->refusal)->toBe('boat_unavailable')
        ->and($save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(inChargeId: $offCrew->id))->refusal)->toBe('boat_unavailable')
        ->and(app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData)->refusal)->toBe('boat_unavailable')
        ->and(BoatSailing::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a boat turned off since the sailing was stored can stay on it, and assistants need someone in charge', function () {
    $setup = sailingSetup();
    $save = app(SaveSailing::class);

    expect($save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['boat']->id, assistantIds: [$setup['razak']->id]))->refusal)->toBe('in_charge_required');

    $save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['din']->id));
    $setup['spare']->update(['active' => false]);

    expect($save($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['razak']->id))->isRefused())->toBeFalse()
        ->and(BoatSailing::query()->sole()->crew->pluck('name')->all())->toBe(['Razak']);
});

test('moving guests to another boat of the same departure keeps the fare, clears the tick and follows the sailing time', function () {
    $setup = sailingSetup();
    $fare = sailingFare($setup['property'], '35.00');
    $setup['departure']->update(['charge_item_id' => $fare->id]);
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, setsTimes: true, leavesAt: '09:30', arrivesAt: '10:15'))->sailing;
    $trip = sailingTrip($setup, $setup['departure']);
    $trip->forceFill(['ticked_at' => now(), 'ticked_by' => $setup['owner']->id])->save();
    $rowsBefore = UnitAddOn::query()->where('reservation_unit_id', $trip->reservation_unit_id)->pluck('id')->all();

    $result = app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$trip->id], $added->id);
    $trip->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($trip->boat_sailing_id)->toBe($added->id)
        ->and($trip->boat_departure_id)->toBe($setup['departure']->id)
        ->and($trip->ticked_at)->toBeNull()
        ->and($trip->reservationUnit->arrival_time)->toBe('10:15:00')
        ->and(UnitAddOn::query()->where('reservation_unit_id', $trip->reservation_unit_id)->pluck('id')->all())->toBe($rowsBefore)
        ->and($rowsBefore)->not->toBeEmpty()
        ->and(Activity::query()->where('event', 'reservation.boat_trip_moved')->count())->toBe(1);

    // Back to the main sailing: no sailing id, and the unit takes the main sailing's time.
    $back = app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$trip->id], null, $setup['departure']->id);

    expect($back->isRefused())->toBeFalse()
        ->and($trip->refresh()->boat_sailing_id)->toBeNull()
        ->and($trip->reservationUnit->arrival_time)->toBe('09:45:00');
});

test('moving guests to another departure changes the fare through SetBoatTrip and puts them on its main sailing', function () {
    $setup = sailingSetup();
    $cheap = sailingFare($setup['property'], '35.00');
    $dear = sailingFare($setup['property'], '50.00');
    $setup['departure']->update(['charge_item_id' => $cheap->id]);
    $setup['other']->update(['charge_item_id' => $dear->id]);
    $trip = sailingTrip($setup, $setup['departure']);

    $result = app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$trip->id], null, $setup['other']->id);
    $trip->refresh();
    $rows = UnitAddOn::query()->where('reservation_unit_id', $trip->reservation_unit_id)->get();

    expect($result->isRefused())->toBeFalse()
        ->and($trip->boat_departure_id)->toBe($setup['other']->id)
        ->and($trip->boat_sailing_id)->toBeNull()
        ->and($trip->reservationUnit->arrival_time)->toBe('11:45:00')
        ->and($rows->pluck('charge_item_id')->unique()->all())->toBe([$dear->id]);
});

test('a move is all or nothing and names the room that is refused', function () {
    $setup = sailingSetup();
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $good = sailingTrip($setup, $setup['departure']);
    $bad = sailingTrip($setup, $setup['departure']);
    $bad->reservationUnit->update(['arrival_date' => '2026-10-21']);

    expect(app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$good->id, $bad->id], $added->id)->refusal)->toBe('target_mismatch')
        ->and($good->refresh()->boat_sailing_id)->toBeNull();

    $bad->reservationUnit->update(['arrival_date' => '2026-10-20']);
    $setup['other']->update(['active' => false]);
    $result = app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$good->id, $bad->id], null, $setup['other']->id);

    expect($result->refusal)->toBe('departure_not_found')
        ->and($result->unitLabel)->not->toBeNull()
        ->and($good->refresh()->boat_departure_id)->toBe($setup['departure']->id)
        ->and(app(MoveBoatTrips::class)($setup['owner'], $setup['property'], [$good->id], $added->id + 999)->refusal)->toBe('sailing_not_found');
});

test('a sailing time moves the unit times of its trips, and a timetable change leaves them alone', function () {
    $setup = sailingSetup();
    $onMain = sailingTrip($setup, $setup['departure']);
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id))->sailing;
    $onAdded = sailingTrip($setup, $setup['departure']);
    $onAdded->update(['boat_sailing_id' => $added->id]);
    $checkedIn = sailingTrip($setup, $setup['departure']);

    $result = app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(
        boatId: $setup['boat']->id,
        inChargeId: $setup['din']->id,
        assistantIds: [$setup['razak']->id],
        setsTimes: true,
        leavesAt: '09:15',
        arrivesAt: '10:00',
    ));

    expect($result->isRefused())->toBeFalse()
        ->and($onMain->reservationUnit->refresh()->arrival_time)->toBe('10:00:00')
        ->and($checkedIn->reservationUnit->refresh()->arrival_time)->toBe('10:00:00')
        ->and($onAdded->reservationUnit->refresh()->arrival_time)->toBe('09:45:00')
        ->and(sailingMain($setup)->timeChanged)->toBeTrue()
        ->and(app(SailingLabels::class)->times(sailingMain($setup)))->toBe('09:15 Kuala Besut jetty → 10:00 (changed)');

    // The timetable moves to 09:50: the added boat (no time of its own) follows, the main sailing (own time) does not.
    app(UpdateBoatDeparture::class)($setup['owner'], $setup['departure'], [
        'direction' => 'in', 'leaves_at' => '09:05', 'arrives_at' => '09:50', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20, 'charge_item_id' => null, 'default_boat_id' => $setup['boat']->id, 'active' => true,
    ]);

    expect($onMain->reservationUnit->refresh()->arrival_time)->toBe('10:00:00')
        ->and($onAdded->reservationUnit->refresh()->arrival_time)->toBe('09:50:00');

    // Back to the timetable through Default: the guests of the main sailing take the timetable time again.
    $reset = app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date']);

    expect($reset->refusal)->toBe('sailing_has_guests');

    $onAdded->update(['boat_sailing_id' => null]);

    expect(app(ResetDepartureDate::class)($setup['owner'], $setup['departure'], $setup['date'])->isRefused())->toBeFalse()
        ->and($onMain->reservationUnit->refresh()->arrival_time)->toBe('09:50:00');
});

test('times that are not in order are refused', function () {
    $setup = sailingSetup();

    $result = app(SaveSailing::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['boat']->id, setsTimes: true, leavesAt: '10:00', arrivesAt: '09:30'));

    expect($result->refusal)->toBe('times_invalid')->and(BoatSailing::query()->count())->toBe(0);
});

test('the seat limit of a departure is the total of its sailings, or none when any sailing has none', function () {
    $setup = sailingSetup();
    $dates = app(DateSailings::class);
    $seats = app(BoatSeats::class);
    $limit = fn () => $seats->limitsForDate($setup['property'], $setup['date'], [$setup['departure']->id, $setup['other']->id]);

    // Main with a boat of 12 seats; the other departure has no boat, so its own seats (20).
    expect($limit())->toBe([$setup['departure']->id => 12, $setup['other']->id => 20]);

    app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id));

    expect($limit()[$setup['departure']->id])->toBe(20);

    // A boat with no seats takes the departure's 20 seats.
    $setup['spare']->update(['seats' => null]);

    expect($limit()[$setup['departure']->id])->toBe(32);

    // A departure with no seats and boats with none has no limit.
    $setup['departure']->update(['seats' => null]);
    $setup['boat']->update(['seats' => null]);

    expect($limit()[$setup['departure']->id])->toBeNull()
        ->and($dates->forDate($setup['property'], $setup['date'], [$setup['departure']->id])[$setup['departure']->id][1]->seats)->toBeNull();
});

test('the manifest has a section per sailing, with its own seats', function () {
    $setup = sailingSetup();
    $added = app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['razak']->id))->sailing;
    sailingTrip($setup, $setup['departure']);
    $moved = sailingTrip($setup, $setup['departure'], ['adults' => 3]);
    $moved->update(['boat_sailing_id' => $added->id]);
    sailingTrip($setup, $setup['other']);
    $report = app(BoatManifestReport::class);
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In);

    $rows = $report->rows($setup['property'], $filters);
    $sections = $report->sections($rows, $filters);

    expect($rows)->toHaveCount(3)
        ->and(array_map(fn ($section) => [$section->title, $section->subtotal], $sections))->toBe([
            ['09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 1 · Din (in charge), Razak', '2 / 12 seats'],
            ['09:00 Kuala Besut jetty → 09:45 · MV Sand Bay 2 · Razak (in charge)', '3 / 8 seats'],
            ['11:00 Kuala Besut jetty → 11:45', '2 / 20 seats'],
        ])
        ->and(collect($rows)->pluck('boat')->all())->toBe(['MV Sand Bay 1', 'MV Sand Bay 2', null])
        ->and(collect($rows)->pluck('crew')->all())->toBe(['Din (in charge), Razak', 'Razak (in charge)', null]);
});

test('the database refuses a job with a departure, a guest sailing with no direction and a second one in charge', function () {
    $setup = sailingSetup();
    $sailing = BoatSailing::factory()->forDeparture($setup['departure'])->create();

    $insert = fn (array $row) => fn () => DB::transaction(fn () => DB::table('boat_sailings')->insert([...$row, 'property_id' => $setup['property']->id, 'date' => '2026-10-20', 'created_at' => now(), 'updated_at' => now()]));

    expect($insert(['kind' => 'job', 'job_type' => 'diesel', 'boat_departure_id' => $setup['departure']->id, 'leaves_at' => '08:00']))->toThrow(Exception::class)
        ->and($insert(['kind' => 'guests', 'boat_departure_id' => $setup['departure']->id]))->toThrow(Exception::class)
        ->and($insert(['kind' => 'guests', 'direction' => 'in', 'leaves_at' => '09:00', 'arrives_at' => '08:00', 'boat_departure_id' => $setup['departure']->id]))->toThrow(Exception::class);

    $sailing->crew()->attach($setup['din']->id, ['in_charge' => true]);

    expect(fn () => DB::transaction(fn () => $sailing->crew()->attach($setup['razak']->id, ['in_charge' => true])))->toThrow(Exception::class);
});

test('the migration splits the crew text, makes the first name in charge and moves each run to a main sailing', function () {
    $setup = sailingSetup();
    $migration = require database_path('migrations/2026_11_10_090001_move_boat_runs_and_default_crew_to_sailings.php');
    $property = $setup['property'];

    // Go back to the old shape: the runs table and the free-text crew come back, the crew lists stay as they are.
    $migration->down();
    $departure = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::Out, 'leaves_at' => '12:00', 'arrives_at' => '12:45']);
    DB::table('boat_departures')->where('id', $departure->id)->update(['default_crew' => 'Awang / Daus, awang , Din']);
    DB::table('boat_departure_runs')->insert([
        ['property_id' => $property->id, 'boat_departure_id' => $departure->id, 'date' => '2026-10-20', 'boat_id' => $setup['boat']->id, 'crew' => 'Razak/Mat', 'created_at' => now(), 'updated_at' => now()],
        ['property_id' => $property->id, 'boat_departure_id' => $departure->id, 'date' => '2026-10-21', 'boat_id' => $setup['spare']->id, 'crew' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    BoatSailing::query()->delete();

    $migration->up();

    $crewNames = fn ($table, $owner, $id) => DB::table($table)->join('boat_crew_members', 'boat_crew_members.id', '=', "{$table}.boat_crew_member_id")->where($owner, $id)->orderBy("{$table}.id")->get(['boat_crew_members.name', "{$table}.in_charge"])->map(fn ($row) => $row->name.($row->in_charge ? '*' : ''))->all();
    $first = BoatSailing::query()->whereDate('date', '2026-10-20')->where('boat_departure_id', $departure->id)->sole();
    $second = BoatSailing::query()->whereDate('date', '2026-10-21')->where('boat_departure_id', $departure->id)->sole();

    expect($crewNames('boat_departure_crew', 'boat_departure_id', $departure->id))->toBe(['Awang*', 'Daus', 'Din'])
        ->and($crewNames('boat_departure_crew', 'boat_departure_id', $setup['departure']->id))->toBe(['Din*', 'Razak'])
        ->and($first->boat_id)->toBe($setup['boat']->id)
        ->and($first->direction->value)->toBe('out')
        ->and($crewNames('boat_sailing_crew', 'boat_sailing_id', $first->id))->toBe(['Razak*', 'Mat'])
        ->and($second->boat_id)->toBe($setup['spare']->id)
        ->and($crewNames('boat_sailing_crew', 'boat_sailing_id', $second->id))->toBe([])
        ->and(BoatCrewMember::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', ['awang'])->count())->toBe(1)
        ->and(BoatCrewMember::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', ['din'])->count())->toBe(1)
        ->and(Schema::hasTable('boat_departure_runs'))->toBeFalse()
        ->and(Schema::hasColumn('boat_departures', 'default_crew'))->toBeFalse();

    // Down: the runs come back with the crew joined by " / ", the one in charge first.
    $migration->down();

    expect(DB::table('boat_departure_runs')->whereDate('date', '2026-10-20')->where('boat_departure_id', $departure->id)->value('crew'))->toBe('Razak / Mat')
        ->and(DB::table('boat_departure_runs')->whereDate('date', '2026-10-21')->where('boat_departure_id', $departure->id)->value('crew'))->toBeNull()
        ->and(DB::table('boat_departures')->where('id', $departure->id)->value('default_crew'))->toBe('Awang / Daus / Din');

    $migration->up();
});

test('a cancelled sailing is left out of the printed sheet, and an empty sailing still prints with its crew', function () {
    $setup = sailingSetup();
    BoatSailing::factory()->forDeparture($setup['other'])->cancelled()->create(['date' => '2026-10-20']);
    app(AddSailingBoat::class)($setup['owner'], $setup['departure'], $setup['date'], new SailingData(boatId: $setup['spare']->id, inChargeId: $setup['razak']->id));
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In);

    $data = app(BoatManifestReport::class)->printData($setup['property'], $filters, []);

    expect(collect($data['sections'])->map(fn (array $section): array => [$section['heading'], $section['boat'], collect($section['crew'])->pluck('name')->all()])->all())->toBe([
        ['0900', 'MV Sand Bay 1', ['Din', 'Razak']],
        ['0900', 'MV Sand Bay 2', ['Razak']],
        ['CHARTER', 'Charter', []],
        ['OWN BOAT', 'Own boat', []],
    ]);
});
