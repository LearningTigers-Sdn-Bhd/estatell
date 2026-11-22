<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Services\Reports\HistoryForecastReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function historyForecastUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/history-forecast".$query;
}

/**
 * A room type with five rooms, a room revenue code with 10% tax, and the rate plan the units use.
 *
 * @return array{roomType: RoomType, roomCode: TransactionCode, plan: RatePlan, folio: Folio}
 */
function historyForecastSetup(Property $property): array
{
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(5)->for($property)->create(['room_type_id' => $roomType->id]);
    $roomCode = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    Tax::factory()->for($property)->create(['rate' => '10.00'])->applicableTransactionCodes()->attach($roomCode);
    $plan = RatePlan::factory()->for($property)->create();

    return ['roomType' => $roomType, 'roomCode' => $roomCode, 'plan' => $plan, 'folio' => Folio::factory()->create(['property_id' => $property->id])];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function historyForecastUnit(Property $property, array $setup, string $arrival, string $departure, array $attributes = [], ?string $state = null): ReservationUnit
{
    return ReservationUnit::factory()
        ->when($state !== null, fn ($factory) => $factory->{$state}())
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->withNights()
        ->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function historyForecastLine(Folio $folio, TransactionCode $code, string $amount, string $date, array $attributes = []): FolioLine
{
    return FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $code->id, 'kind' => $code->kind, 'amount' => $amount, 'unit_amount' => ltrim($amount, '-'), 'posting_date' => $date, ...$attributes]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function historyForecastRows(Property $property, string $from, string $to): array
{
    return collect(app(HistoryForecastReport::class)->rows($property, new ReportFilters($from, $to)))
        ->mapWithKeys(fn ($row): array => [$row->key => [...$row->toArray(), 'basis' => $row->basis->value]])
        ->all();
}

test('the page needs reports.view, is scoped to the property and opens on the business month', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();

    $this->actingAs($front)->get(historyForecastUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(historyForecastUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(historyForecastUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/history-forecast')
        ->where('from', '2026-10-01')
        ->where('to', '2026-10-31')
        ->where('sort', 'date')
        ->has('layout.columns', 13)
        ->where('layout.orientation', 'landscape')
        ->where('rows.meta.total', 22));
});

test('a closed date reads the audit snapshot and the ledger, with complimentary nights out of ADR', function () {
    [, $property] = reportSetup();
    $setup = historyForecastSetup($property);
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-08', 'rooms_available' => 10, 'rooms_sold' => 4, 'beds_available' => 0, 'beds_sold' => 0, 'in_house_guests' => 7]);
    historyForecastUnit($property, $setup, '2026-10-08', '2026-10-09', ['rate_plan_id' => RatePlan::factory()->for($property)->complimentary()->create()->id], 'checkedOut');
    $food = TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Charge, 'category' => TransactionCategory::FoodAndBeverage]);
    historyForecastLine($setup['folio'], $setup['roomCode'], '300.00', '2026-10-08');
    historyForecastLine($setup['folio'], $food, '50.00', '2026-10-08');
    historyForecastLine($setup['folio'], Tax::query()->sole()->transactionCode, '28.00', '2026-10-08');
    historyForecastLine($setup['folio'], TransactionCode::factory()->for($property)->system(SystemTransactionCode::CashPayment)->create(), '-200.00', '2026-10-08');
    historyForecastLine($setup['folio'], TransactionCode::factory()->for($property)->system(SystemTransactionCode::DirectBillPayment)->create(), '-100.00', '2026-10-08');

    expect(historyForecastRows($property, '2026-10-08', '2026-10-08')['2026-10-08'])->toMatchArray([
        'basis' => 'actual',
        'available' => 10,
        'sold' => 4,
        'complimentary' => 1,
        'occupancy' => '40.00',
        'adr' => '100.00',
        'revpar' => '30.00',
        'pax' => 7,
        'room_charges' => '300.00',
        'extra_charges' => '50.00',
        'tax' => '28.00',
        'receipts' => '200.00',
    ]);
});

test('a later date forecasts from the books at today\'s prices and taxes, with no receipts', function () {
    [, $property] = reportSetup();
    $setup = historyForecastSetup($property);
    historyForecastUnit($property, $setup, '2026-10-11', '2026-10-13', ['adults' => 2, 'children' => 1]);
    $comp = historyForecastUnit($property, $setup, '2026-10-11', '2026-10-12', ['adults' => 2, 'children' => 0, 'rate_plan_id' => RatePlan::factory()->for($property)->complimentary()->create()->id]);
    $comp->nights()->update(['base_amount' => '0.00', 'amount' => '0.00']);
    historyForecastUnit($property, $setup, '2026-10-11', '2026-10-12', ['status' => UnitStatus::Cancelled]);

    expect(historyForecastRows($property, '2026-10-11', '2026-10-11')['2026-10-11'])->toMatchArray([
        'basis' => 'forecast',
        'available' => 5,
        'sold' => 2,
        'complimentary' => 1,
        'occupancy' => '40.00',
        'adr' => '100.00',
        'revpar' => '20.00',
        'pax' => 5,
        'room_charges' => '100.00',
        'extra_charges' => '0.00',
        'tax' => '10.00',
        'receipts' => null,
    ]);
});

test('today adds what is posted to what is still to post, and never counts a posted night twice', function () {
    [, $property] = reportSetup();
    $setup = historyForecastSetup($property);
    $posted = historyForecastUnit($property, $setup, '2026-10-10', '2026-10-11', state: 'checkedIn');
    historyForecastUnit($property, $setup, '2026-10-10', '2026-10-11');
    historyForecastLine($setup['folio'], $setup['roomCode'], '100.00', '2026-10-10', ['idempotency_key' => 'night:'.$posted->nights()->sole()->id]);
    historyForecastLine($setup['folio'], TransactionCode::factory()->for($property)->create(['kind' => TransactionKind::Charge, 'category' => TransactionCategory::FoodAndBeverage]), '20.00', '2026-10-10');
    historyForecastLine($setup['folio'], TransactionCode::factory()->for($property)->system(SystemTransactionCode::CashPayment)->create(), '-50.00', '2026-10-10');

    expect(historyForecastRows($property, '2026-10-10', '2026-10-10')['2026-10-10'])->toMatchArray([
        'basis' => 'today',
        'sold' => 2,
        'room_charges' => '200.00',
        'extra_charges' => '20.00',
        'tax' => '10.00',
        'receipts' => '50.00',
    ]);
});

test('the total row sums the figures and works the rates out again', function () {
    [, $property] = reportSetup();
    $setup = historyForecastSetup($property);
    historyForecastUnit($property, $setup, '2026-10-11', '2026-10-13');

    $totals = ReportKey::HistoryForecast->totals(app(HistoryForecastReport::class)->rows($property, new ReportFilters('2026-10-11', '2026-10-12')));

    expect($totals->figures)->toMatchArray([
        'available' => 10,
        'sold' => 2,
        'occupancy' => '20.00',
        'adr' => '100.00',
        'room_charges' => '200.00',
        'tax' => '20.00',
        'receipts' => '0.00',
    ]);
});

test('the rows cost the same number of queries however many bookings the dates hold', function () {
    [, $property] = reportSetup();
    $setup = historyForecastSetup($property);
    $count = function () use ($property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(HistoryForecastReport::class)->rows($property, new ReportFilters('2026-10-08', '2026-10-20'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    historyForecastUnit($property, $setup, '2026-10-11', '2026-10-14');
    Model::preventLazyLoading();

    try {
        $count();
        $few = $count();

        foreach (range(1, 3) as $ignored) {
            historyForecastUnit($property, $setup, '2026-10-12', '2026-10-15');
        }

        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});
