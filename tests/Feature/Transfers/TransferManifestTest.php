<?php

use App\Data\Reports\ReportFilters;
use App\Data\Reports\TransferManifestRow;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\TransferDriver;
use App\Models\TransferVehicle;
use App\Models\UnitGuest;
use App\Models\UnitTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/TransferHelpers.php';

/**
 * The fixture's unit (3 pax, in 20 Oct) with a transfer in at 14:00 on Van 1 with Ali, a 4 pax unit leaving 20 Oct
 * with a transfer out at 11:00 on the same van and driver, and a 1 pax unit arriving 20 Oct with a transfer in at 09:00
 * and no vehicle or driver. Also a cancelled unit and a transfer of another date, which the manifest leaves out.
 *
 * @return array<string, mixed>
 */
function transferManifestFixture(): array
{
    $fixture = transferFixture();
    $property = $fixture['property'];
    $fixture['reservation']->booker->update(['full_name' => 'Tan Wei Ling', 'phone' => '+60123456789']);
    $vehicle = TransferVehicle::factory()->for($property)->create(['name' => 'Van 1', 'plate' => 'SA 1234 B']);
    $driver = TransferDriver::factory()->for($property)->create(['name' => 'Ali', 'subcontractor' => null]);
    $unit = fn (array $attributes): ReservationUnit => ReservationUnit::factory()->for($fixture['reservation'])->create([
        'property_id' => $property->id,
        'status' => UnitStatus::Confirmed,
        'children' => 0,
        ...$attributes,
    ]);
    $transfer = fn (ReservationUnit $unit, array $attributes): UnitTransfer => UnitTransfer::factory()->create([
        'property_id' => $property->id,
        'reservation_unit_id' => $unit->id,
        'transfer_route_id' => $fixture['in']->id,
        ...$attributes,
    ]);

    $fixture['afternoon'] = $transfer($fixture['unit'], ['pickup_time' => '14:00', 'transfer_vehicle_id' => $vehicle->id, 'transfer_driver_id' => $driver->id, 'flight_number' => 'AK 6231']);
    $fixture['leaving'] = $transfer($unit(['arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20', 'adults' => 4]), ['direction' => 'out', 'transfer_route_id' => $fixture['out']->id, 'pickup_time' => '11:00', 'transfer_vehicle_id' => $vehicle->id, 'transfer_driver_id' => $driver->id]);
    $fixture['morning'] = $transfer($unit(['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'adults' => 1]), ['pickup_time' => '09:00']);
    $transfer($unit(['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'adults' => 2, 'status' => UnitStatus::Cancelled]), ['pickup_time' => '08:00']);
    $transfer($fixture['unit'], ['direction' => 'out', 'transfer_route_id' => $fixture['out']->id, 'pickup_time' => '07:00']);

    return $fixture;
}

/** @param  array<string, mixed>  $fixture */
function transferManifestUrl(array $fixture, string $kind): string
{
    return route("transfers.{$kind}", $fixture['property']->code);
}

test('the manifest lists the date\'s transfers by pick-up time, one section per vehicle and driver, with the unassigned last', function () {
    $fixture = transferManifestFixture();
    $this->actingAs($fixture['owner']);
    setPermissionsTeamId($fixture['property']->id);
    $report = ReportKey::TransferManifest;
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20');

    $rows = $report->report()->rows($fixture['property'], $filters);
    $sections = $report->sections($rows, $filters);

    expect(array_map(fn (TransferManifestRow $row): string => $row->pickup, $rows))->toBe(['09:00', '11:00', '14:00'])
        ->and($rows[2]->lead_guest)->toBe('Tan Wei Ling')
        ->and($rows[2]->vehicle)->toBe('Van 1 · SA 1234 B')
        ->and($rows[2]->driver)->toBe('Ali')
        ->and($rows[2]->flight)->toBe('AK 6231')
        ->and($rows[1]->direction)->toBe('Transfer out')
        ->and(array_map(fn ($section): string => $section->title, $sections))->toBe(['Van 1 · SA 1234 B · Ali', 'Vehicle and driver not set'])
        ->and($sections[0]->rowKeys)->toBe([$fixture['leaving']->id, $fixture['afternoon']->id])
        ->and($sections[0]->subtotal)->toBe('2 transfers · 7 pax')
        ->and($sections[1]->rowKeys)->toBe([$fixture['morning']->id])
        ->and($sections[1]->tone)->toBe(Tone::Warning)
        ->and($report->totals($rows)->figures['total'])->toBe(8);
});

test('the run sheet exports the manifest as a PDF and a CSV for reservations.view, with guest names only for guests.view', function () {
    $fixture = transferManifestFixture();
    $driverDesk = User::factory()->for($fixture['property'])->withPermissions([PermissionEnum::ReservationsView])->create();
    $nobody = User::factory()->for($fixture['property'])->withPermissions([PermissionEnum::GuestsView])->create();

    $this->actingAs($fixture['owner'])
        ->get(route('transfers.index', [$fixture['property']->code, 'date' => '2026-10-20']))
        ->assertInertia(fn ($page) => $page
            ->where('canEditColumns', true)
            ->where('layout.orientation', 'landscape')
            ->where('layout.columns.0.key', 'pickup'));

    $owner = $this->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertOk();
    expect(file_get_contents($owner->getFile()->getPathname()))
        ->toContain('Pick-up')
        ->toContain('Tan Wei Ling')
        ->toContain('+60123456789')
        ->toContain('Van 1 · SA 1234 B');

    $this->actingAs($driverDesk)
        ->post(transferManifestUrl($fixture, 'pdf'), ['date' => '2026-10-20'])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $desk = $this->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertOk();
    expect(file_get_contents($desk->getFile()->getPathname()))
        ->toContain('Airport to hotel')
        ->not->toContain('Tan Wei Ling')
        ->not->toContain('+60123456789');

    $this->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20'])->assertSessionHasErrors('format');

    $this->actingAs($nobody)->post(transferManifestUrl($fixture, 'pdf'), ['date' => '2026-10-20'])->assertForbidden();
    $this->actingAs($nobody)->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertForbidden();
});

test('the exports need the Transfers feature and keep to the transfers of the property', function () {
    $fixture = transferManifestFixture();
    $other = transferManifestFixture();
    $off = transferFixture(featureOn: false);

    $response = $this->actingAs($fixture['owner'])->post(transferManifestUrl($fixture, 'spreadsheet'), [
        'date' => '2026-10-20',
        'format' => 'csv',
        'rowKeys' => [$fixture['morning']->id, $other['afternoon']->id],
    ])->assertOk();

    expect(file_get_contents($response->getFile()->getPathname()))
        ->toContain('09:00')
        ->not->toContain('14:00');

    $this->post("/p/{$fixture['property']->code}/reports/transfer-manifest/pdf", ['date' => '2026-10-20'])->assertOk();

    $this->actingAs($off['owner'])->post(transferManifestUrl($off, 'pdf'), ['date' => '2026-10-20'])->assertNotFound();
    $this->actingAs($off['owner'])->post(transferManifestUrl($off, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertNotFound();
    $this->actingAs($off['owner'])->post("/p/{$off['property']->code}/reports/transfer-manifest/pdf", ['date' => '2026-10-20'])->assertNotFound();
});

test('the export query count does not grow with the number of transfers', function () {
    $fixture = transferManifestFixture();
    $count = function () use ($fixture): int {
        Model::preventLazyLoading();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($fixture['owner'])->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        Model::preventLazyLoading(false);

        return $queries;
    };
    $count();
    $few = $count();

    foreach (range(1, 5) as $index) {
        $unit = ReservationUnit::factory()->for($fixture['reservation'])->create(['property_id' => $fixture['property']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-21', 'status' => UnitStatus::Confirmed]);
        UnitTransfer::factory()->create(['property_id' => $fixture['property']->id, 'reservation_unit_id' => $unit->id, 'transfer_route_id' => $fixture['in']->id, 'transfer_driver_id' => TransferDriver::factory()->for($fixture['property'])->create()->id]);
    }

    expect($count())->toBe($few);
});

test('the manifest prints the bags with their total, and the lodge\'s duty and emergency phones under the title', function () {
    $fixture = transferManifestFixture();
    $fixture['afternoon']->update(['bags' => 3]);
    $fixture['leaving']->update(['bags' => 2]);
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20');
    $report = ReportKey::TransferManifest;

    expect($report->note($fixture['property'], $filters))->toBeNull();

    $fixture['property']->settings->update(['duty_manager_phone' => '+60 12 111 2222', 'emergency_phone' => '999']);
    $this->actingAs($fixture['owner']);
    setPermissionsTeamId($fixture['property']->id);
    $rows = $report->report()->rows($fixture['property']->fresh(), $filters);

    expect($report->note($fixture['property']->fresh(), $filters))->toBe('In an emergency, call: Duty manager +60 12 111 2222 · Emergency 999')
        ->and($report->totals($rows)->figures['bags'])->toBe(5)
        ->and($rows[0]->cells()['bags'])->toBe('');

    $csv = $this->post(transferManifestUrl($fixture, 'spreadsheet'), ['date' => '2026-10-20', 'format' => 'csv'])->assertOk();
    expect(file_get_contents($csv->getFile()->getPathname()))->toContain('Bags');
});

test('the manifest names the room\'s guests with nationality and age for guests.view, and counts the ones not named', function () {
    $fixture = transferManifestFixture();
    $property = $fixture['property'];
    $lead = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Hannah Müller', 'nationality_code' => 'DE', 'date_of_birth' => '1990-10-21']);
    $friend = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tom Lee', 'nationality_code' => 'GB', 'date_of_birth' => null]);
    UnitGuest::factory()->create(['reservation_unit_id' => $fixture['unit']->id, 'guest_id' => $friend->id, 'is_primary' => false]);
    UnitGuest::factory()->create(['reservation_unit_id' => $fixture['unit']->id, 'guest_id' => $lead->id, 'is_primary' => true]);
    $desk = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20');
    setPermissionsTeamId($property->id);

    $this->actingAs($fixture['owner']);
    $rows = collect(ReportKey::TransferManifest->report()->rows($property, $filters))->keyBy('key');

    expect($rows[$fixture['afternoon']->id]->party)->toBe('Hannah Müller (DE, 35) · Tom Lee (GB) · +1 not named')
        ->and($rows[$fixture['morning']->id]->party)->toStartWith('Tan Wei Ling')
        ->and(collect(ReportKey::TransferManifest->columns())->firstWhere('key', 'party')->defaultVisible)->toBeTrue();

    $this->actingAs($desk);

    expect(collect(ReportKey::TransferManifest->report()->rows($property, $filters))->firstWhere('key', $fixture['afternoon']->id)->party)->toBeNull();
});
