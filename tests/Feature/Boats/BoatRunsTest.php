<?php

use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatDeparture;
use App\Models\BoatDepartureRun;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use App\Services\Reports\BoatManifestReport;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * An owner of a property with the Boats feature on and a Boat in departure at 09:00 from Jetty Pelancongan.
 *
 * @return array{owner: User, property: Property, departure: BoatDeparture}
 */
function boatRunsSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    Feature::for($property)->activate(Boats::class);
    $departure = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Jetty Pelancongan']);

    return ['owner' => $owner, 'property' => $property, 'departure' => $departure];
}

test('owners add and change boats, and a name is used once per property', function () {
    $setup = boatRunsSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['owner'])->post(route('settings.boats.boats.store', $code), ['name' => 'SA10716', 'registration' => ''])->assertSessionHasNoErrors();
    $boat = Boat::query()->sole();
    expect($boat->name)->toBe('SA10716')->and($boat->registration)->toBeNull()->and($boat->active)->toBeTrue();

    $this->post(route('settings.boats.boats.store', $code), ['name' => 'sa10716'])->assertSessionHasErrors('name');
    $this->put(route('settings.boats.boats.update', [$code, $boat]), ['name' => 'SA10716', 'registration' => 'SAB 123', 'active' => '0'])->assertSessionHasNoErrors();

    expect($boat->refresh()->registration)->toBe('SAB 123')->and($boat->active)->toBeFalse();

    $this->get(route('settings.boats.boats.index', $code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/boats/boats')->where('boats.0.name', 'SA10716'));
});

test('the boats tab needs settings.manage and another property boat is a 404', function () {
    $setup = boatRunsSetup();
    $other = Boat::factory()->create();
    $frontDesk = User::factory()->for($setup['property'])->withPermissions(['reports.view'])->create();

    $this->actingAs($frontDesk)->get(route('settings.boats.boats.index', $setup['property']->code))->assertForbidden();
    $this->actingAs($setup['owner'])->put(route('settings.boats.boats.update', [$setup['property']->code, $other]), ['name' => 'X'])->assertNotFound();
});

test('the charter charge is an active item with a fixed price', function () {
    $setup = boatRunsSetup();
    $charter = ChargeItem::factory()->for($setup['property'])->create(['default_amount' => '280.00']);
    $priceless = ChargeItem::factory()->for($setup['property'])->create(['default_amount' => null]);
    $url = route('settings.boats.charter', $setup['property']->code);

    $this->actingAs($setup['owner'])->put($url, ['charge_item_id' => $priceless->id])->assertSessionHasErrors('charge_item_id');
    $this->put($url, ['charge_item_id' => $charter->id])->assertSessionHasNoErrors();

    expect($setup['property']->settings->refresh()->charter_charge_item_id)->toBe($charter->id);

    $this->put($url, ['charge_item_id' => null])->assertSessionHasNoErrors();

    expect($setup['property']->settings->refresh()->charter_charge_item_id)->toBeNull();
});

test('staff set the boat and crew of a departure on a date, and the manifest section names them', function () {
    $setup = boatRunsSetup();
    $boat = Boat::factory()->for($setup['property'])->create(['name' => 'SA10716']);
    $url = route('reports.boat-manifest.runs', $setup['property']->code);
    $body = ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $boat->id, 'crew' => 'Awang / Daus'];

    $this->actingAs($setup['owner'])->put($url, $body)->assertSessionHasNoErrors();
    $this->put($url, $body)->assertSessionHasNoErrors();

    $run = BoatDepartureRun::query()->sole();
    $report = app(BoatManifestReport::class);
    expect($run->boat_id)->toBe($boat->id)->and($run->crew)->toBe('Awang / Daus')
        ->and($report->runLabels([$setup['departure']->id], '2026-10-20'))->toBe([$setup['departure']->id => 'SA10716 · Awang / Daus'])
        ->and($report->runs($setup['property'], new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In))[0]->crew)->toBe('Awang / Daus');

    $this->put($url, [...$body, 'boat_id' => null, 'crew' => ''])->assertSessionHasNoErrors();

    expect(BoatDepartureRun::query()->count())->toBe(0);
});

test('a run refuses a boat of another property or a boat not in use, and needs reservations.manage', function () {
    $setup = boatRunsSetup();
    $url = route('reports.boat-manifest.runs', $setup['property']->code);
    $off = Boat::factory()->for($setup['property'])->create(['active' => false]);
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reports.view'])->create();

    $this->actingAs($setup['owner'])->put($url, ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => Boat::factory()->create()->id])->assertSessionHasErrors('boat_id');
    $this->put($url, ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'boat_id' => $off->id])->assertSessionHasErrors('boat_id');
    $this->actingAs($viewer)->put($url, ['boat_departure_id' => $setup['departure']->id, 'date' => '2026-10-20', 'crew' => 'Awang'])->assertForbidden();

    expect(BoatDepartureRun::query()->count())->toBe(0);
});

test('manifest inherits default boat and crew from departure timetable and supports daily overrides and reverts', function () {
    $setup = boatRunsSetup();
    $defaultBoat = Boat::factory()->for($setup['property'])->create(['name' => 'MV Sand Bay 1']);
    $otherBoat = Boat::factory()->for($setup['property'])->create(['name' => 'MV Sand Bay 2']);

    $setup['departure']->update([
        'default_boat_id' => $defaultBoat->id,
        'default_crew' => 'Awang / Daus',
    ]);

    $report = app(BoatManifestReport::class);
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In);

    // 1. Without any daily override, defaults are used
    expect($report->runLabels([$setup['departure']->id], '2026-10-20'))->toBe([
        $setup['departure']->id => 'MV Sand Bay 1 · Awang / Daus',
    ]);
    $runs = $report->runs($setup['property'], $filters);
    expect($runs)->toHaveCount(1)
        ->and($runs[0]->boatId)->toBe($defaultBoat->id)
        ->and($runs[0]->crew)->toBe('Awang / Daus')
        ->and($runs[0]->defaultBoatId)->toBe($defaultBoat->id)
        ->and($runs[0]->defaultCrew)->toBe('Awang / Daus')
        ->and($runs[0]->isOverridden)->toBeFalse();

    // 2. Staff save a daily override
    $url = route('reports.boat-manifest.runs', $setup['property']->code);
    $this->actingAs($setup['owner'])->put($url, [
        'boat_departure_id' => $setup['departure']->id,
        'date' => '2026-10-20',
        'boat_id' => $otherBoat->id,
        'crew' => 'Din / Razak',
    ])->assertSessionHasNoErrors();

    expect($report->runLabels([$setup['departure']->id], '2026-10-20'))->toBe([
        $setup['departure']->id => 'MV Sand Bay 2 · Din / Razak',
    ]);
    $runsAfterOverride = $report->runs($setup['property'], $filters);
    expect($runsAfterOverride[0]->boatId)->toBe($otherBoat->id)
        ->and($runsAfterOverride[0]->crew)->toBe('Din / Razak')
        ->and($runsAfterOverride[0]->isOverridden)->toBeTrue();

    // 3. Staff revert back to timetable default
    $this->put($url, [
        'boat_departure_id' => $setup['departure']->id,
        'date' => '2026-10-20',
        'reset_to_default' => true,
    ])->assertSessionHasNoErrors();

    expect(BoatDepartureRun::query()->count())->toBe(0)
        ->and($report->runLabels([$setup['departure']->id], '2026-10-20'))->toBe([
            $setup['departure']->id => 'MV Sand Bay 1 · Awang / Daus',
        ]);
    $runsAfterRevert = $report->runs($setup['property'], $filters);
    expect($runsAfterRevert[0]->boatId)->toBe($defaultBoat->id)
        ->and($runsAfterRevert[0]->crew)->toBe('Awang / Daus')
        ->and($runsAfterRevert[0]->isOverridden)->toBeFalse();
});

test('staff batch save all departures in one form submission', function () {
    $setup = boatRunsSetup();
    $secondDeparture = BoatDeparture::factory()->for($setup['property'])->create([
        'direction' => BoatDirection::In,
        'leaves_at' => '11:30',
        'arrives_at' => '12:15',
        'pickup_point' => 'Jetty Pelancongan',
    ]);
    $boat1 = Boat::factory()->for($setup['property'])->create(['name' => 'Boat 1']);
    $boat2 = Boat::factory()->for($setup['property'])->create(['name' => 'Boat 2']);

    $url = route('reports.boat-manifest.runs', $setup['property']->code);

    $this->actingAs($setup['owner'])->put($url, [
        'date' => '2026-10-25',
        'runs' => [
            [
                'boat_departure_id' => $setup['departure']->id,
                'boat_id' => $boat1->id,
                'crew' => 'Captain Jack',
            ],
            [
                'boat_departure_id' => $secondDeparture->id,
                'boat_id' => $boat2->id,
                'crew' => 'Captain Morgan',
            ],
        ],
    ])->assertSessionHasNoErrors();

    expect(BoatDepartureRun::query()->count())->toBe(2);

    $report = app(BoatManifestReport::class);
    $labels = $report->runLabels([$setup['departure']->id, $secondDeparture->id], '2026-10-25');
    expect($labels)->toBe([
        $setup['departure']->id => 'Boat 1 · Captain Jack',
        $secondDeparture->id => 'Boat 2 · Captain Morgan',
    ]);
});
