<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Enums\BookingSourceType;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\Company;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Database\Factories\BookingSourceFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * `migratedStaySetup()` (live since 10 Oct, business date 14 Oct, window open) with the room type named "Deluxe King"
 * and an Old system payment method.
 *
 * @return array<string, mixed>
 */
function arrivalImportSetup(): array
{
    $setup = migratedStaySetup();
    $setup['roomType']->update(['name' => 'Deluxe King']);
    $code = TransactionCode::factory()->system(SystemTransactionCode::OldSystemPayment)->create(['property_id' => $setup['property']->id]);
    PaymentMethod::factory()->inactive()->create([
        'property_id' => $setup['property']->id,
        'transaction_code_id' => $code->id,
        'name' => 'Old system',
        'type' => PaymentMethodType::OldSystem,
        'requires_reference' => false,
    ]);

    return $setup;
}

/**
 * An eZee Arrival List CSV export with the given data rows.
 *
 * @param  list<array<string, string>>  $rows
 */
function ezeeArrivalFile(array $rows): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('arrivals-').'.csv';
    $writer = SimpleExcelWriter::create($path)->addHeader(['Res. No', 'Guest', 'Room', 'Rate(RM)', 'Arrival', 'Departure', 'Nights', 'Pax', 'Res.Type', 'Company', 'Deposit(RM)', 'Business Source', 'Reservation Remarks']);

    foreach ($rows as $row) {
        $writer->addRow([
            'Res. No' => '', 'Guest' => '', 'Room' => '', 'Rate(RM)' => '', 'Arrival' => '', 'Departure' => '', 'Nights' => '', 'Pax' => ' 2 / 0',
            'Res.Type' => 'Confirm Booking', 'Company' => '', 'Deposit(RM)' => '0.00', 'Business Source' => '', 'Reservation Remarks' => '', ...$row,
        ]);
    }

    $writer->close();

    return new UploadedFile($path, 'arrivals.csv', null, null, true);
}

/**
 * A guest in room 101 since 12 Oct, a two-room group arriving 20 Oct (one room unassigned) and a released booking.
 */
function sandBayStyleFile(): UploadedFile
{
    return ezeeArrivalFile([
        ['Res. No' => 'RES100', 'Guest' => ' Mr.JANE TAN', 'Room' => ' 101 - Deluxe Room (King)', 'Rate(RM)' => '150.00', 'Arrival' => '12/10/2026 03:00:00 PM', 'Departure' => '16/10/2026 12:00:00 PM', 'Nights' => '4', 'Deposit(RM)' => '200.00'],
        ['Res. No' => 'RES200-1', 'Guest' => 'Ms.LIM MEI', 'Room' => ' 102 - Deluxe Room (King)', 'Rate(RM)' => '180.00', 'Arrival' => '20/10/2026 03:00:00 PM', 'Departure' => '22/10/2026 12:00:00 PM', 'Nights' => '2', 'Deposit(RM)' => '100.00', 'Reservation Remarks' => 'BOAT IN: 1030'],
        ['Res. No' => 'RES200-2', 'Guest' => 'Mr.ONG KAH', 'Room' => ' - Deluxe Room (King)', 'Rate(RM)' => '180.00', 'Arrival' => '20/10/2026 03:00:00 PM', 'Departure' => '22/10/2026 12:00:00 PM', 'Nights' => '2'],
        ['Res. No' => 'RES300', 'Guest' => 'Mr.GONE', 'Room' => ' 102 - Deluxe Room (King)', 'Rate(RM)' => '180.00', 'Arrival' => '24/10/2026 03:00:00 PM', 'Departure' => '25/10/2026 12:00:00 PM', 'Nights' => '1', 'Res.Type' => 'Released'],
    ]);
}

function arrivalImportUrl(Property $property, string $path = ''): string
{
    return route('reservations.import', ['property' => $property->code]).$path;
}

test('the preview reads an eZee export: past stay, group booking and skipped rows', function () {
    $setup = arrivalImportSetup();

    $response = $this->actingAs($setup['user'])->post(arrivalImportUrl($setup['property'], '/preview'), ['file' => sandBayStyleFile()])->assertOk();

    $response->assertJsonCount(4, 'rows')
        ->assertJsonPath('rows.0.guest', 'JANE TAN')
        ->assertJsonPath('rows.0.outcome', 'past_in_house')
        ->assertJsonPath('rows.0.total', '600.00')
        ->assertJsonPath('rows.0.deposit', '200.00')
        ->assertJsonPath('rows.0.arrivalTime', '15:00')
        ->assertJsonPath('rows.1.groupNo', 'RES200')
        ->assertJsonPath('rows.1.outcome', 'booking')
        ->assertJsonPath('rows.1.source', 'Phone')
        ->assertJsonPath('rows.2.room', null)
        ->assertJsonPath('rows.2.roomType', 'Deluxe King')
        ->assertJsonPath('rows.2.problems', [])
        ->assertJsonPath('rows.3.outcome', 'skipped');
});

test('importing creates the bookings, the past stay and the deposits, and a second upload adds nothing', function () {
    $setup = arrivalImportSetup();

    $this->actingAs($setup['user'])
        ->post(arrivalImportUrl($setup['property']), ['file' => sandBayStyleFile(), 'reason' => 'Imported from eZee'])
        ->assertRedirect(route('reservations.index', ['property' => $setup['property']->code]))
        ->assertInertiaFlash('toast.type', 'success');

    $reservations = Reservation::query()->where('property_id', $setup['property']->id)->orderBy('id')->get();
    expect($reservations->pluck('external_reference')->all())->toBe(['RES100', 'RES200']);

    $past = $reservations[0]->units()->sole();
    expect($past->status)->toBe(UnitStatus::CheckedIn)
        ->and(UnitNight::query()->where('reservation_unit_id', $past->id)->sum('amount'))->toEqual(600);

    $group = $reservations[1]->units()->orderBy('id')->get();
    expect($group)->toHaveCount(2)
        ->and($group->pluck('status')->all())->toBe([UnitStatus::Confirmed, UnitStatus::Confirmed])
        ->and(UnitNight::query()->where('reservation_unit_id', $group[0]->id)->sum('amount'))->toEqual(360)
        ->and(UnitNight::query()->where('reservation_unit_id', $group[0]->id)->value('room_id'))->toBe($setup['rooms'][1]->id)
        ->and(UnitNight::query()->where('reservation_unit_id', $group[1]->id)->value('room_id'))->toBeNull();

    expect(Payment::query()->where('property_id', $setup['property']->id)->pluck('amount')->map(fn ($amount): string => (string) $amount)->sort()->values()->all())->toBe(['100.00', '200.00'])
        ->and(Activity::query()->where('event', 'reservation.arrivals_imported')->sole()->properties['units'])->toBe(3);

    $this->actingAs($setup['user'])
        ->post(arrivalImportUrl($setup['property']), ['file' => sandBayStyleFile(), 'reason' => 'Imported from eZee'])
        ->assertInertiaFlash('toast.type', 'error');

    expect(Reservation::query()->where('property_id', $setup['property']->id)->count())->toBe(2)
        ->and(Payment::query()->where('property_id', $setup['property']->id)->count())->toBe(2);
});

test('a row with a problem refuses the whole import', function () {
    $setup = arrivalImportSetup();
    $file = fn (): UploadedFile => ezeeArrivalFile([
        ['Res. No' => 'RES400', 'Guest' => 'Ms.OK', 'Room' => ' 102 - Deluxe Room (King)', 'Rate(RM)' => '180.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1'],
        ['Res. No' => 'RES401', 'Guest' => 'Mr.LOST', 'Room' => ' 999 - Deluxe Room (King)', 'Rate(RM)' => '180.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1'],
    ]);

    $this->actingAs($setup['user'])->post(arrivalImportUrl($setup['property'], '/preview'), ['file' => $file()])
        ->assertJsonPath('rows.0.problems', [])
        ->assertJsonPath('rows.1.problems.0', 'Unknown room 999.');

    $this->actingAs($setup['user'])
        ->post(arrivalImportUrl($setup['property']), ['file' => $file(), 'reason' => 'Imported from eZee'])
        ->assertInertiaFlash('toast.type', 'error');

    expect(Reservation::query()->where('property_id', $setup['property']->id)->exists())->toBeFalse();
});

test('a refusal at import rolls back every booking and names the row', function () {
    $setup = arrivalImportSetup();

    $this->actingAs($setup['user'])
        ->post(arrivalImportUrl($setup['property']), ['file' => ezeeArrivalFile([
            ['Res. No' => 'RES500', 'Guest' => 'Ms.FIRST', 'Room' => ' 101 - Deluxe Room (King)', 'Rate(RM)' => '100.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1'],
            ['Res. No' => 'RES501', 'Guest' => 'Ms.SECOND', 'Room' => ' 102 - Deluxe Room (King)', 'Rate(RM)' => '100.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1'],
            ['Res. No' => 'RES502', 'Guest' => 'Ms.THIRD', 'Room' => ' - Deluxe Room (King)', 'Rate(RM)' => '100.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1'],
        ]), 'reason' => 'Imported from eZee'])
        ->assertInertiaFlash('toast.message', 'Row 4: No unit of this room type is free for these dates.');

    expect(Reservation::query()->where('property_id', $setup['property']->id)->exists())->toBeFalse();
});

test('the template has the estatell columns', function () {
    $setup = arrivalImportSetup();

    $response = $this->actingAs($setup['user'])->get(route('reservations.import-template', ['property' => $setup['property']->code, 'format' => 'csv']))->assertOk();

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('res_no,guest,room,room_type,arrival,departure');
});

test('the import needs folio.manual and the property of the user', function () {
    $setup = arrivalImportSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsManage])->create();

    $this->actingAs($clerk)->get(arrivalImportUrl($setup['property']))->assertForbidden();
    $this->actingAs($setup['user'])->get(arrivalImportUrl($setup['property']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('reservations/import')->where('openBusinessDate', '2026-10-14')->has('businessDate.date'));
    $this->actingAs($setup['user'])->get(arrivalImportUrl(Property::factory()->create()))->assertNotFound();
});

test('boat times become boat trips on a boat property, and an unknown time is a problem', function () {
    $setup = arrivalImportSetup();
    Feature::for($setup['property'])->activate(Boats::class);
    $boatIn = BoatDeparture::factory()->create(['property_id' => $setup['property']->id, 'leaves_at' => '10:30', 'arrives_at' => '11:15']);
    $file = fn (string $remarks): UploadedFile => ezeeArrivalFile([
        ['Res. No' => 'RES600', 'Guest' => 'Ms.SEA', 'Room' => ' 102 - Deluxe Room (King)', 'Rate(RM)' => '100.00', 'Arrival' => '20/10/2026', 'Departure' => '21/10/2026', 'Nights' => '1', 'Reservation Remarks' => $remarks],
    ]);

    $this->actingAs($setup['user'])->post(arrivalImportUrl($setup['property'], '/preview'), ['file' => $file('(1) BOAT IN TIME: 0800 / BOAT OUT TIME: OWN BOAT')])
        ->assertJsonPath('rows.0.problems.0', 'No Boat in departure at 08:00.');

    $this->actingAs($setup['user'])
        ->post(arrivalImportUrl($setup['property']), ['file' => $file('(1) BOAT IN : 1030 / BOAT OUT : OWN BOAT'), 'reason' => 'Imported from eZee'])
        ->assertInertiaFlash('toast.type', 'success');

    $unit = Reservation::query()->where('property_id', $setup['property']->id)->sole()->units()->sole();
    expect(DB::table('unit_boat_trips')->where('reservation_unit_id', $unit->id)->orderBy('direction')->get(['direction', 'mode', 'boat_departure_id'])->map(fn ($trip): array => (array) $trip)->all())
        ->toBe([
            ['direction' => 'in', 'mode' => 'scheduled', 'boat_departure_id' => $boatIn->id],
            ['direction' => 'out', 'mode' => 'own', 'boat_departure_id' => null],
        ]);
});

test('the estatell template reads a company hold, a TBA boat and a released row', function () {
    $setup = arrivalImportSetup();
    $company = Company::factory()->create(['property_id' => $setup['property']->id, 'name' => 'Perfect Vacation Sdn.Bhd']);
    $path = sys_get_temp_dir().'/'.uniqid('arrivals-').'.csv';
    SimpleExcelWriter::create($path)->addRows([
        ['res_no' => 'RES4848-1', 'guest' => 'PERFECT VACATION (SABRINA CHIN)', 'room' => '101', 'room_type' => 'Deluxe King', 'arrival' => '2026-10-20 15:00', 'departure' => '2026-10-21', 'adults' => '2', 'children' => '0', 'total' => '1,620.00', 'deposit' => '', 'company' => 'PERFECT VACATION SDN.BHD', 'source' => '', 'status' => 'hold', 'boat_in' => 'TBA', 'boat_out' => 'TBA', 'notes' => 'Agent: SABRINA CHIN'],
        ['res_no' => 'RES4456-1', 'guest' => 'KUMARAN', 'room' => '102', 'room_type' => 'Deluxe King', 'arrival' => '2026-10-20', 'departure' => '2026-10-21', 'adults' => '2', 'children' => '0', 'total' => '2620.00', 'deposit' => '', 'company' => '', 'source' => '', 'status' => 'released', 'boat_in' => '', 'boat_out' => '', 'notes' => ''],
    ])->close();

    $this->actingAs($setup['user'])->post(arrivalImportUrl($setup['property'], '/preview'), ['file' => new UploadedFile($path, 'sandbay.csv', null, null, true)])
        ->assertJsonPath('rows.0.outcome', 'hold')
        ->assertJsonPath('rows.0.total', '1620.00')
        ->assertJsonPath('rows.0.companyId', $company->id)
        ->assertJsonPath('rows.0.source', 'Company / Travel agent')
        ->assertJsonPath('rows.0.problems', [])
        ->assertJsonPath('rows.1.outcome', 'skipped');
});

test('a file without the template columns is refused before any row is read', function () {
    $setup = arrivalImportSetup();
    $path = sys_get_temp_dir().'/'.uniqid('arrivals-').'.csv';
    SimpleExcelWriter::create($path)->addRow(['res_no' => 'RES4874', 'guest_name' => 'JEROME', 'arrival_date' => '2026-11-03', 'departure_date' => '2026-11-04', 'adults' => '2', 'total_amount_myr' => '724.0'])->close();

    $this->actingAs($setup['user'])->postJson(arrivalImportUrl($setup['property'], '/preview'), ['file' => new UploadedFile($path, 'other.csv', null, null, true)])
        ->assertUnprocessable()
        ->assertJsonPath('errors.file.0', 'This file is not an arrival list: it has no guest, arrival, departure, total column. Use the template (Template ▾) or an arrival list export from your old PMS.');
});

test('an arrival list cannot put a stay on the Booking Engine source', function () {
    $setup = arrivalImportSetup();
    BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::BookingEngine);
    $path = sys_get_temp_dir().'/'.uniqid('arrivals-').'.csv';
    SimpleExcelWriter::create($path)->addRow(['res_no' => 'RES4900-1', 'guest' => 'KUMARAN', 'room' => '101', 'room_type' => 'Deluxe King', 'arrival' => '2026-10-20', 'departure' => '2026-10-21', 'adults' => '2', 'children' => '0', 'total' => '200.00', 'deposit' => '', 'company' => '', 'source' => 'Booking Engine', 'status' => '', 'boat_in' => '', 'boat_out' => ''])->close();

    $this->actingAs($setup['user'])->post(arrivalImportUrl($setup['property'], '/preview'), ['file' => new UploadedFile($path, 'arrivals.csv', null, null, true)])
        ->assertJsonPath('rows.0.problems.0', 'Unknown booking source Booking Engine.');
});
