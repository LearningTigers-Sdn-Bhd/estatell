<?php

use App\Data\Reports\ArrivalRow;
use App\Enums\DefaultRoleEnum;
use App\Enums\PageOrientation;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function exportSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function exportUnit(Property $property, string $arrival = '2026-10-10', string $guestName = 'Guest', string $balance = '0.00'): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => date('Y-m-d', strtotime($arrival.' +2 days')),
    ]);
    UnitGuest::factory()->primary()->create([
        'reservation_unit_id' => $unit->id,
        'guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => $guestName])->id,
    ]);

    if ($balance !== '0.00') {
        $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $booking->id]);
        FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $balance, 'unit_amount' => $balance]);
    }

    return $unit;
}

function exportUrl(Property $property, string $kind): string
{
    return "/p/{$property->code}/reports/arrivals/{$kind}";
}

/**
 * Captures the data the PDF view is rendered with.
 *
 * @return object{data: array<string, mixed>}
 */
function capturePdfView(): object
{
    $captured = new stdClass;
    $captured->data = [];
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    return $captured;
}

/**
 * @return list<list<string>>
 */
function csvRows(string $content): array
{
    return array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($content, "\xEF\xBB\xBF")))));
}

test('the PDF is returned inline and lists every row that matches the dates', function () {
    [$user, $property] = exportSetup();
    exportUnit($property);
    exportUnit($property);
    exportUnit($property, '2026-10-20');
    $captured = capturePdfView();

    $response = $this->actingAs($user)->post(exportUrl($property, 'pdf'), ['from' => '2026-10-10', 'to' => '2026-10-10']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($captured->data['rows'])->toHaveCount(2)
        ->and($captured->data['selectedOnly'])->toBeFalse();
});

test('the PDF holds only the selected rows and ignores keys outside the dates or the property', function () {
    [$user, $property] = exportSetup();
    [, $other] = exportSetup();
    $picked = exportUnit($property);
    exportUnit($property);
    $later = exportUnit($property, '2026-10-20');
    $foreign = exportUnit($other);
    $captured = capturePdfView();

    $this->actingAs($user)->post(exportUrl($property, 'pdf'), [
        'from' => '2026-10-10',
        'to' => '2026-10-10',
        'rowKeys' => [(string) $picked->id, (string) $later->id, (string) $foreign->id],
    ])->assertOk();

    expect(array_map(fn (ArrivalRow $row): int => $row->key, $captured->data['rows']))->toBe([$picked->id])
        ->and($captured->data['selectedOnly'])->toBeTrue();
});

test('the PDF follows the sort and the saved layout orientation', function () {
    [$user, $property] = exportSetup();
    $a = exportUnit($property, guestName: 'Anna');
    $b = exportUnit($property, guestName: 'Zed');
    ReportLayout::factory()->create(['property_id' => $property->id, 'orientation' => PageOrientation::Landscape]);
    $captured = capturePdfView();

    $this->actingAs($user)->post(exportUrl($property, 'pdf'), ['from' => '2026-10-10', 'to' => '2026-10-10', 'sort' => '-guest'])->assertOk();

    expect(array_map(fn (ArrivalRow $row): int => $row->key, $captured->data['rows']))->toBe([$b->id, $a->id]);
});

test('more than 500 row keys are refused', function () {
    [$user, $property] = exportSetup();

    $this->actingAs($user)->postJson(exportUrl($property, 'pdf'), ['rowKeys' => range(1, 501)])->assertUnprocessable()->assertJsonValidationErrors('rowKeys');
    $this->actingAs($user)->postJson(exportUrl($property, 'spreadsheet'), ['format' => 'csv', 'rowKeys' => range(1, 501)])->assertUnprocessable()->assertJsonValidationErrors('rowKeys');
});

test('the spreadsheet needs a valid format and valid dates', function () {
    [$user, $property] = exportSetup();

    $this->actingAs($user)->postJson(exportUrl($property, 'spreadsheet'), [])->assertJsonValidationErrors('format');
    $this->actingAs($user)->postJson(exportUrl($property, 'spreadsheet'), ['format' => 'pdf'])->assertJsonValidationErrors('format');
    $this->actingAs($user)->postJson(exportUrl($property, 'spreadsheet'), ['format' => 'csv', 'from' => '2026-10-12', 'to' => '2026-10-10'])->assertJsonValidationErrors('to');
});

test('the CSV follows the saved layout order and visibility and writes money as numbers', function () {
    [$user, $property] = exportSetup();
    $unit = exportUnit($property, guestName: 'Tan', balance: '350.50');
    $layout = ReportLayout::factory()->create(['property_id' => $property->id]);

    foreach ([['balance', true], ['guest', true], ['reservation', false], ['arrival', true], ['nights', true]] as $position => [$key, $visible]) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position, 'is_visible' => $visible]);
    }

    $response = $this->actingAs($user)->post(exportUrl($property, 'spreadsheet'), ['format' => 'csv', 'from' => '2026-10-10', 'to' => '2026-10-10']);

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('arrivals-2026-10-10.csv');

    $rows = csvRows($response->streamedContent());

    expect($rows[0])->toBe(['Balance', 'Guest', 'Arrival', 'Nights', 'Arrival time', 'Room type / room', 'Status'])
        ->and($rows[1])->toBe(['350.5', 'Tan', '2026-10-10', '2', '15:00', $unit->roomType->name, 'Confirmed']);
});

test('the spreadsheet sorts like the table and honours the selection', function () {
    [$user, $property] = exportSetup();
    $a = exportUnit($property, guestName: 'Anna');
    $b = exportUnit($property, guestName: 'Zed');
    exportUnit($property, guestName: 'Mia');

    $all = $this->actingAs($user)->post(exportUrl($property, 'spreadsheet'), ['format' => 'csv', 'sort' => '-guest']);
    $picked = $this->actingAs($user)->post(exportUrl($property, 'spreadsheet'), ['format' => 'csv', 'sort' => 'guest', 'rowKeys' => [$b->id, $a->id]]);

    expect(array_column(csvRows($all->streamedContent()), 1))->toBe(['Guest', 'Zed', 'Mia', 'Anna'])
        ->and(array_column(csvRows($picked->streamedContent()), 1))->toBe(['Guest', 'Anna', 'Zed']);
});

test('the Excel file downloads', function () {
    [$user, $property] = exportSetup();
    exportUnit($property);

    $response = $this->actingAs($user)->post(exportUrl($property, 'spreadsheet'), ['format' => 'xlsx', 'from' => '2026-10-10', 'to' => '2026-10-11']);

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('arrivals-2026-10-10-2026-10-11.xlsx')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toStartWith('PK');
});

test('exports need reports.view but not settings.manage, and another property returns 404', function () {
    [$front, $frontProperty] = exportSetup(DefaultRoleEnum::FrontDesk);
    [$finance, $financeProperty] = exportSetup(DefaultRoleEnum::Finance);
    [, $other] = exportSetup();

    $this->actingAs($front)->post(exportUrl($frontProperty, 'pdf'))->assertForbidden();
    $this->actingAs($front)->post(exportUrl($frontProperty, 'spreadsheet'), ['format' => 'csv'])->assertForbidden();
    $this->actingAs($finance)->post(exportUrl($financeProperty, 'pdf'))->assertOk();
    $this->actingAs($finance)->post(exportUrl($financeProperty, 'spreadsheet'), ['format' => 'csv'])->assertOk();
    $this->actingAs($finance)->post(exportUrl($other, 'pdf'))->assertNotFound();
});
