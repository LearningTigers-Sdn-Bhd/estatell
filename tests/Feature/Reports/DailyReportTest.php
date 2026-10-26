<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\PaymentStatus;
use App\Enums\ReportKey;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function dailyUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/daily-report".$query;
}

function dailyCode(Property $property, ?SystemTransactionCode $system, TransactionCategory $category = TransactionCategory::Other): TransactionCode
{
    if ($system !== null) {
        return TransactionCode::query()->where('property_id', $property->id)->where('system_key', $system->value)->first()
            ?? TransactionCode::factory()->for($property)->system($system)->create();
    }

    return TransactionCode::factory()->for($property)->create(['category' => $category]);
}

function dailyLine(Property $property, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge, ?FolioLine $sibling = null, bool $migrated = false): FolioLine
{
    $folio = $sibling === null
        ? Folio::factory()->create(['property_id' => $property->id])
        : Folio::query()->findOrFail($sibling->folio_id);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
        'migrated' => $migrated,
    ]);
}

/**
 * Two reservations on 9 and 10 October: A has room charges on both days with SST, B has a fee, a minibar charge, day
 * use, a discount and a flat tourism tax on the 9th. Lines the report must leave out are added too.
 */
function dailyData(Property $property): void
{
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST', 'code' => 'SST']);
    $tourism = Tax::factory()->create(['property_id' => $property->id, 'name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => TaxRateType::Flat]);
    $room = dailyCode($property, SystemTransactionCode::RoomRevenue);

    $a1 = dailyLine($property, $room, '100.00', '2026-10-09');
    dailyLine($property, $sst->transactionCode, '8.00', '2026-10-09', TransactionKind::Tax, $a1);
    $a2 = dailyLine($property, $room, '150.00', '2026-10-10', sibling: $a1);
    dailyLine($property, $sst->transactionCode, '12.00', '2026-10-10', TransactionKind::Tax, $a2);

    $b = dailyLine($property, dailyCode($property, null, TransactionCategory::Fee), '20.00', '2026-10-09');
    dailyLine($property, dailyCode($property, null, TransactionCategory::Minibar), '30.00', '2026-10-09', sibling: $b);
    dailyLine($property, dailyCode($property, SystemTransactionCode::DayUseRevenue), '40.00', '2026-10-09', sibling: $b);
    dailyLine($property, dailyCode($property, null, TransactionCategory::Discount), '-10.00', '2026-10-09', TransactionKind::Adjustment, $b);
    dailyLine($property, $tourism->transactionCode, '10.00', '2026-10-09', TransactionKind::Tax, $b);

    dailyLine($property, $room, '500.00', '2026-10-09', migrated: true);
    dailyLine($property, dailyCode($property, SystemTransactionCode::OpeningBalance), '700.00', '2026-10-09');
    dailyLine($property, $room, '-999.00', '2026-10-09', TransactionKind::Payment);
    dailyLine($property, $room, '900.00', '2026-10-01');
}

function dailyPayments(Property $property): void
{
    $card = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Card']);
    $pay = function (string $amount, string $date, PaymentStatus $status = PaymentStatus::Completed, ?Payment $refundOf = null) use ($property, $card): Payment {
        $folio = Folio::factory()->create(['property_id' => $property->id]);
        $signed = $refundOf === null ? "-{$amount}" : $amount;
        $line = FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $card->transaction_code_id, 'kind' => TransactionKind::Payment, 'amount' => $signed, 'unit_amount' => $signed, 'posting_date' => $date]);

        return Payment::factory()->create([
            'folio_id' => $folio->id, 'property_id' => $property->id, 'payment_method_id' => $card->id, 'amount' => $amount, 'status' => $status,
            'refund_of_id' => $refundOf?->id, 'receipt_number' => $refundOf === null ? 'R-'.fake()->unique()->numerify('######') : null, 'folio_line_id' => $line->id,
        ]);
    };
    $paid = $pay('300.00', '2026-10-09');
    $pay('50.00', '2026-10-10', refundOf: $paid);
    $pay('20.00', '2026-10-10', PaymentStatus::Pending);
}

test('the page needs reports.view, is scoped to the property and opens Overview for a bad tab', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();

    $this->actingAs($front)->get(dailyUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(dailyUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(dailyUrl($own, '?tab=bogus'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/daily-report')
        ->where('tab', 'overview')
        ->where('tabs.0.value', 'overview')
        ->where('tabs.1.href', "/p/{$own->code}/reports/daily-report?tab=revenue&from=2026-10-10&to=2026-10-10")
        ->where('tabs.2.label', 'Cashier activity')
        ->has('overview')
        ->missing('rows'));
});

test('the revenue tab splits charges into buckets with one column for each tax', function () {
    [$user, $property] = reportSetup();
    dailyData($property);
    [, $other] = reportSetup();
    dailyData($other);

    $this->actingAs($user)->get(dailyUrl($property, '?tab=revenue&from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'revenue')
        ->where('maxRangeDays', 366)
        ->where('sort', 'period')
        ->where('layout.columns', fn ($columns) => collect($columns)->pluck('key')->all() === ['period', 'bookings', 'accommodation', 'day_use', 'fees', 'extras', 'tax:SST', 'tax:TTX', 'adjustments', 'total'])
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['bookings'], $row['accommodation'], $row['day_use'], $row['fees'], $row['extras'], $row['taxes'], $row['adjustments'], $row['total']])->all() === [
            ['2026-10-09', 2, '100.00', '40.00', '20.00', '30.00', ['SST' => '8.00', 'TTX' => '10.00'], '-10.00', '198.00'],
            ['2026-10-10', 1, '150.00', '0.00', '0.00', '0.00', ['SST' => '12.00', 'TTX' => '0.00'], '0.00', '162.00'],
        ])
        ->missing('rows.data.0.reservationIds')
        ->where('totals.figures', fn ($figures) => $figures['bookings'] === 2
            && $figures['accommodation'] === '250.00'
            && $figures['tax:SST'] === '20.00'
            && $figures['tax:TTX'] === '10.00'
            && $figures['adjustments'] === '-10.00'
            && $figures['total'] === '360.00'));
});

test('a month groups its days and counts each reservation once', function () {
    [$user, $property] = reportSetup();
    dailyData($property);

    $this->actingAs($user)->get(dailyUrl($property, '?tab=revenue&from=2026-10-02&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('group', 'month')
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.period', '2026-10')
        ->where('rows.data.0.bookings', 2)
        ->where('rows.data.0.total', '360.00'));
});

test('rows sort by a tax column', function () {
    [$user, $property] = reportSetup();
    dailyData($property);

    $this->actingAs($user)->get(dailyUrl($property, '?tab=revenue&from=2026-10-09&to=2026-10-10&sort=-tax:SST'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-tax:SST')
        ->where('rows.data.0.key', '2026-10-10')
        ->where('rows.data.1.key', '2026-10-09'));
});

test('the overview cards match the revenue and cashier figures', function () {
    [$user, $property] = reportSetup();
    dailyData($property);
    dailyPayments($property);

    $this->actingAs($user)->get(dailyUrl($property, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('overview.bookings', 2)
        ->where('overview.totalCharges', '370.00')
        ->where('overview.adjustments', '-10.00')
        ->where('overview.netRevenue', '360.00')
        ->where('overview.movements', 2)
        ->where('overview.collected', '300.00')
        ->where('overview.refunded', '-50.00')
        ->where('overview.netAtDesk', '250.00'));
});

test('the cashier tab is the Payments report with the staff summary as the default', function () {
    [$user, $property] = reportSetup();
    dailyPayments($property);

    $this->actingAs($user)->get(dailyUrl($property, '?tab=cashier&from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'cashier')
        ->where('group', 'staff')
        ->where('sort', 'staff')
        ->where('breakdown.title', 'Cash-up by staff')
        ->has('layout.columns', 9)
        ->where('rows.meta.total', 3)
        ->where('totals.figures.amount', '250.00'));

    $this->actingAs($user)->get(dailyUrl($property, '?tab=cashier&from=2026-10-09&to=2026-10-10&group=method'))->assertInertia(fn (Assert $page) => $page
        ->where('group', 'method')
        ->where('breakdown.title', 'Total by method'));
});

test('a saved layout gets a new tax column before Adjustments', function () {
    [, $property] = reportSetup();
    $layout = ReportLayout::factory()->create(['property_id' => $property->id, 'report_key' => ReportKey::DailyRevenue->value]);

    foreach (['total', 'adjustments', 'period', 'bookings'] as $position => $key) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position, 'is_visible' => $key !== 'bookings']);
    }

    $keys = fn (): array => array_map(fn ($column): string => $column->key, app(ReportLayoutResolver::class)->resolve($property, ReportKey::DailyRevenue)->columns);

    expect($keys())->toBe(['total', 'adjustments', 'period', 'bookings', 'accommodation', 'day_use', 'fees', 'extras']);

    Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST', 'code' => 'SST']);
    Tax::factory()->create(['property_id' => $property->id, 'name' => 'Tourism tax', 'code' => 'TTX']);

    expect($keys())->toBe(['total', 'tax:SST', 'tax:TTX', 'adjustments', 'period', 'bookings', 'accommodation', 'day_use', 'fees', 'extras']);
});

test('a property without a tax has no tax column', function () {
    [, $property] = reportSetup();

    expect(array_map(fn ($column): string => $column->key, ReportKey::DailyRevenue->columnsFor($property)))
        ->toBe(['period', 'bookings', 'accommodation', 'day_use', 'fees', 'extras', 'adjustments', 'total']);
});

test('the PDF and CSV keep the tax columns and the distinct booking total', function () {
    [$user, $property] = reportSetup();
    dailyData($property);
    $base = ['from' => '2026-10-09', 'to' => '2026-10-10'];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/daily-revenue/pdf", $base)->assertOk();
    expect($captured->data['rows'])->toHaveCount(2);

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/daily-revenue/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows[0])->toBe(['Date / month', 'Bookings', 'Accommodation', 'Day use', 'Fees', 'Extras', 'SST', 'Tourism tax', 'Adjustments', 'Total'])
        ->and($rows[1])->toBe(['2026-10-09', '2', '100', '40', '20', '30', '8', '10', '-10', '198'])
        ->and(end($rows))->toBe(['Total', '2', '250', '40', '20', '30', '20', '10', '-10', '360']);
});
