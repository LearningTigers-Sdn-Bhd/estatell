<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionKind;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function ledgerUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/folio-ledger".$query;
}

function ledgerLine(Folio $folio, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge, ?User $user = null): FolioLine
{
    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'description' => "{$code->code} line",
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
        'user_id' => $user?->id,
    ]);
}

/**
 * Lines of three codes on three dates, one of them a payment.
 *
 * @return array{room: TransactionCode, meal: TransactionCode, lines: array<string, FolioLine>, folio: Folio}
 */
function ledgerData(Property $property): array
{
    $room = TransactionCode::factory()->for($property)->create(['code' => 'ROOM', 'name' => 'Room revenue']);
    $meal = TransactionCode::factory()->for($property)->create(['code' => 'MEAL', 'name' => 'Meals']);
    $cash = TransactionCode::factory()->for($property)->create(['code' => 'CASH', 'name' => 'Cash', 'kind' => TransactionKind::Payment]);
    $folio = Folio::factory()->create(['property_id' => $property->id]);

    return [
        'room' => $room,
        'meal' => $meal,
        'folio' => $folio,
        'lines' => [
            'room1' => ledgerLine($folio, $room, '200.00', '2026-10-08'),
            'room2' => ledgerLine($folio, $room, '200.00', '2026-10-09'),
            'meal' => ledgerLine($folio, $meal, '30.00', '2026-10-09'),
            'cash' => ledgerLine($folio, $cash, '-150.00', '2026-10-10', TransactionKind::Payment),
            'old' => ledgerLine($folio, $room, '999.00', '2026-08-01'),
        ],
    ];
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();
    ledgerData($own);
    ledgerData($other);

    $this->actingAs($front)->get(ledgerUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(ledgerUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(ledgerUrl($own, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/folio-ledger')
        ->where('maxRangeDays', 31)
        ->where('sort', 'date')
        ->where('code', null)
        ->has('layout.columns', 10)
        ->where('layout.columns', fn ($columns) => collect($columns)->firstWhere('key', 'service_date')['visible'] === false)
        ->where('rows.meta.total', 4)
        ->where('totals.figures.amount', '280.00'));
});

test('a range longer than 31 days is refused', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get(ledgerUrl($property, '?from=2026-09-01&to=2026-10-10'))->assertSessionHasErrors('to');
});

test('every kind is listed with its signed amount, folio, reservation, payer and staff', function () {
    [$user, $property] = reportSetup();
    $data = ledgerData($property);
    $company = Company::factory()->create(['property_id' => $property->id]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $companyFolio = Folio::factory()->create(['property_id' => $property->id, 'payer_company_id' => $company->id]);
    $guestFolio = Folio::factory()->create(['property_id' => $property->id, 'payer_guest_id' => $guest->id]);
    ledgerLine($companyFolio, $data['room'], '10.00', '2026-10-10', user: $user);
    ledgerLine($guestFolio, $data['room'], '20.00', '2026-10-10');
    $folio = $data['folio']->load('reservation.booker');

    $this->actingAs($user)->get(ledgerUrl($property, '?from=2026-10-09&to=2026-10-10&sort=-amount'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 5)
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('amount')->all() === ['200.00', '30.00', '20.00', '10.00', '-150.00']
            && collect($rows)->firstWhere('amount', '-150.00')['kind'] === 'Payment'
            && collect($rows)->firstWhere('amount', '200.00')['folio'] === $folio->number
            && collect($rows)->firstWhere('amount', '200.00')['reservation'] === $folio->reservation->reference
            && collect($rows)->firstWhere('amount', '200.00')['guest'] === $folio->reservation->booker->full_name
            && collect($rows)->firstWhere('amount', '10.00')['guest'] === $company->name
            && collect($rows)->firstWhere('amount', '10.00')['staff'] === $user->name
            && collect($rows)->firstWhere('amount', '20.00')['guest'] === $guest->full_name
            && collect($rows)->firstWhere('amount', '20.00')['staff'] === null));
});

test('the code filter keeps one code, lists the property codes and is kept in the page', function () {
    [$user, $property] = reportSetup();
    ledgerData($property);

    $this->actingAs($user)->get(ledgerUrl($property, '?from=2026-10-01&to=2026-10-10&code=ROOM'))->assertInertia(fn (Assert $page) => $page
        ->where('code', 'ROOM')
        ->where('rows.meta.total', 2)
        ->where('totals.figures.amount', '400.00')
        ->where('codeOptions', fn ($options) => collect($options)->pluck('value')->contains('MEAL')
            && collect($options)->firstWhere('value', 'ROOM')['label'] === 'ROOM · Room revenue'));
});

test('the code must be a code of this property', function () {
    [$user, $property] = reportSetup();
    [, $other] = reportSetup();
    TransactionCode::factory()->for($other)->create(['code' => 'OTHERONLY']);
    ledgerData($property);

    $this->actingAs($user)->get(ledgerUrl($property, '?code=OTHERONLY'))->assertSessionHasErrors('code');
    $this->actingAs($user)->get(ledgerUrl($property, '?code=NOPE'))->assertSessionHasErrors('code');
    $this->actingAs($user)->postJson("/p/{$property->code}/reports/folio-ledger/pdf", ['code' => 'NOPE'])->assertJsonValidationErrors('code');
});

test('other reports ignore the code', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get("/p/{$property->code}/reports/in-house?code=NOPE")->assertOk();
    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?code=NOPE")->assertOk();
});

test('the PDF and CSV keep the code and sum only the selected rows', function () {
    [$user, $property] = reportSetup();
    $lines = ledgerData($property)['lines'];
    $base = ['from' => '2026-10-01', 'to' => '2026-10-10', 'code' => 'ROOM', 'rowKeys' => [$lines['room1']->id, $lines['meal']->id, $lines['cash']->id]];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/folio-ledger/pdf", $base)->assertOk();
    expect($captured->data['rows'])->toHaveCount(1)
        ->and($captured->data['totals']->figures['amount'])->toBe('200.00');

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/folio-ledger/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(3)
        ->and($rows[1][4])->toBe('ROOM')
        ->and(end($rows)[8])->toBe('200');
});

test('the revenue drill-down link covers the day, or the month cut to the range', function () {
    [$user, $property] = reportSetup();
    ledgerData($property);

    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?from=2026-10-08&to=2026-10-09")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['from'] === $row['to'] && $row['from'] === $row['period'])));

    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?from=2026-08-15&to=2026-10-09&group=month&sort=period")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['period'], $row['code'], $row['from'], $row['to']])->all() === [
            ['2026-10', 'ROOM', '2026-10-01', '2026-10-09'],
            ['2026-10', 'MEAL', '2026-10-01', '2026-10-09'],
        ]));

    $this->actingAs($user)->get("/p/{$property->code}/reports/revenue?from=2026-07-20&to=2026-10-09&group=month&sort=period")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.from', '2026-08-01')
        ->where('rows.data.0.to', '2026-08-31'));

    $this->actingAs($user)->get(ledgerUrl($property, '?code=ROOM&from=2026-10-01&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.amount', '400.00'));
});
