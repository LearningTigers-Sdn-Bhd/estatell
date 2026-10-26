<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Tax;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function taxUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/tax".$query;
}

function taxLine(Property $property, Tax $tax, string $amount, string $date, TransactionKind $kind = TransactionKind::Tax, bool $migrated = false): FolioLine
{
    $folio = Folio::factory()->create(['property_id' => $property->id]);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $tax->transaction_code_id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
        'migrated' => $migrated,
    ]);
}

/**
 * Two taxes with lines on three dates in two months, a reversal, and a charge on a tax code that is not tax.
 *
 * @return array{sst: Tax, tourism: Tax}
 */
function taxData(Property $property): array
{
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST', 'registration_number' => 'W10-1234']);
    $tourism = Tax::factory()->create(['property_id' => $property->id, 'name' => 'Tourism tax']);
    taxLine($property, $sst, '8.00', '2026-09-30');
    taxLine($property, $sst, '16.00', '2026-10-09');
    taxLine($property, $sst, '4.00', '2026-10-09');
    taxLine($property, $sst, '-4.00', '2026-10-10');
    taxLine($property, $tourism, '10.00', '2026-10-09');
    taxLine($property, $sst, '500.00', '2026-10-09', TransactionKind::Charge);

    return ['sst' => $sst, 'tourism' => $tourism];
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();
    taxData($own);
    taxData($other);

    $this->actingAs($front)->get(taxUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(taxUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(taxUrl($own, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/tax')
        ->where('maxRangeDays', 366)
        ->where('group', 'day')
        ->where('sort', 'period')
        ->has('groupOptions', 2)
        ->has('layout.columns', 6)
        ->where('rows.meta.total', 3));
});

test('days list each tax with its tax lines only, reversals included', function () {
    [$user, $property] = reportSetup();
    ['sst' => $sst, 'tourism' => $tourism] = taxData($property);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-10&sort=amount'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['tax'], $row['registration'], $row['lines'], $row['amount']])->all() === [
            ["2026-10-10:{$sst->transaction_code_id}", 'SST', 'W10-1234', 1, '-4.00'],
            ["2026-10-09:{$tourism->transaction_code_id}", 'Tourism tax', null, 1, '10.00'],
            ["2026-10-09:{$sst->transaction_code_id}", 'SST', 'W10-1234', 2, '20.00'],
        ])
        ->where('totals.figures.lines', 4)
        ->where('totals.figures.amount', '26.00'));
});

test('a month groups its days by tax and keeps only the dates in the range', function () {
    [$user, $property] = reportSetup();
    ['sst' => $sst, 'tourism' => $tourism] = taxData($property);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-09-01&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('group', 'month')
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['lines'], $row['amount']])->all() === [
            ["2026-09:{$sst->transaction_code_id}", 1, '8.00'],
            ["2026-10:{$sst->transaction_code_id}", 3, '16.00'],
            ["2026-10:{$tourism->transaction_code_id}", 1, '10.00'],
        ])
        ->where('totals.figures.amount', '34.00'));

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-01&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 2));
});

test('the group must be day or month', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get(taxUrl($property, '?group=week'))->assertSessionHasErrors('group');
});

test('the PDF and CSV keep the group and total only the selected rows', function () {
    [$user, $property] = reportSetup();
    ['sst' => $sst] = taxData($property);
    $base = ['from' => '2026-09-01', 'to' => '2026-10-31', 'group' => 'month', 'rowKeys' => ["2026-09:{$sst->transaction_code_id}", "2026-10:{$sst->transaction_code_id}"]];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/tax/pdf", $base)->assertOk();
    expect($captured->data['group'])->toBe('Month')
        ->and($captured->data['rows'])->toHaveCount(2)
        ->and($captured->data['totals']->figures['amount'])->toBe('24.00')
        ->and($captured->data['breakdown']->rows)->toBe([]);

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/tax/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['Date / month', 'Tax', 'Lines', 'Base', 'Tax amount'])
        ->and(end($rows))->toBe(['Total', '', '4', '0', '24']);
});

test('tax on migrated lines is a separate group outside the rows and the total', function () {
    [$user, $property] = reportSetup();
    ['sst' => $sst, 'tourism' => $tourism] = taxData($property);
    taxLine($property, $sst, '100.00', '2026-10-05', migrated: true);
    taxLine($property, $sst, '55.52', '2026-10-06', migrated: true);
    taxLine($property, $tourism, '5.00', '2026-10-06', migrated: true);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 3)
        ->where('totals.figures.amount', '26.00')
        ->where('breakdown.title', 'Migrated from old system (not in the total above)')
        ->where('breakdown.headings', ['Tax', 'Lines', 'Amount'])
        ->where('breakdown.rows', [['SST', '2', '155.52'], ['Tourism tax', '1', '5.00']])
        ->where('breakdown.total', ['Total', '3', '160.52']));

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('breakdown.rows', []));
});

/**
 * A charge on a folio with a tax line under it and, when given, a discount booked with the charge (an Adjustment child
 * beside the tax, as PostFolioCharge posts it).
 *
 * @return array{charge: FolioLine, tax: FolioLine}
 */
function chargeWithTax(Tax $tax, string $charge, string $taxAmount, string $date, ?string $discount = null, ?FolioLine $parent = null): array
{
    $folio = Folio::factory()->create(['property_id' => $tax->property_id]);
    $base = ['folio_id' => $folio->id, 'posting_date' => $date];
    $chargeLine = $parent ?? FolioLine::factory()->create([...$base, 'kind' => TransactionKind::Charge, 'amount' => $charge, 'unit_amount' => $charge]);

    if ($discount !== null) {
        FolioLine::factory()->create([...$base, 'kind' => TransactionKind::Adjustment, 'amount' => $discount, 'unit_amount' => $discount, 'parent_line_id' => $chargeLine->id]);
    }

    $taxLine = FolioLine::factory()->create([...$base, 'transaction_code_id' => $tax->transaction_code_id, 'kind' => TransactionKind::Tax, 'amount' => $taxAmount, 'unit_amount' => $taxAmount, 'parent_line_id' => $chargeLine->id]);

    return ['charge' => $chargeLine, 'tax' => $taxLine];
}

test('the base of a percentage tax is its charge, less an inline discount', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    chargeWithTax($sst, '100.00', '8.00', '2026-10-09');
    chargeWithTax($sst, '200.00', '12.80', '2026-10-09', discount: '-40.00');

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->has('layout.columns.4', fn (Assert $column) => $column->where('key', 'base')->where('visible', true)->etc())
        ->where('rows.data.0.base', '260.00')
        ->where('rows.data.0.amount', '20.80')
        ->where('totals.figures.base', '260.00'));
});

test('a tax under a discount line takes the discount as its base', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    $discountLine = FolioLine::factory()->create([
        'folio_id' => Folio::factory()->create(['property_id' => $property->id])->id,
        'kind' => TransactionKind::Adjustment, 'amount' => '-50.00', 'unit_amount' => '-50.00', 'posting_date' => '2026-10-09',
    ]);
    chargeWithTax($sst, '0', '-4.00', '2026-10-09', parent: $discountLine);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.base', '-50.00')
        ->where('rows.data.0.amount', '-4.00'));
});

test('a flat tax shows no base and stays out of the base total', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    $tourism = Tax::factory()->create(['property_id' => $property->id, 'name' => 'Tourism tax', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00']);
    chargeWithTax($sst, '100.00', '8.00', '2026-10-09');
    chargeWithTax($tourism, '100.00', '10.00', '2026-10-09');

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-09&sort=tax'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['tax'], $row['base']])->all() === [['SST', '100.00'], ['Tourism tax', null]])
        ->where('totals.figures.base', '100.00')
        ->where('totals.figures.amount', '18.00'));
});

test('a reversal reduces the base on the date it was posted', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    chargeWithTax($sst, '100.00', '8.00', '2026-10-08');
    $reversedCharge = FolioLine::factory()->create([
        'folio_id' => Folio::factory()->create(['property_id' => $property->id])->id,
        'kind' => TransactionKind::Charge, 'amount' => '-100.00', 'unit_amount' => '-100.00', 'posting_date' => '2026-10-10',
    ]);
    chargeWithTax($sst, '0', '-8.00', '2026-10-10', parent: $reversedCharge);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-08&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['period'], $row['base'], $row['amount']])->all() === [
            ['2026-10-08', '100.00', '8.00'],
            ['2026-10-10', '-100.00', '-8.00'],
        ])
        ->where('totals.figures.base', '0.00'));
});

test('a month adds up the bases of its days', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    chargeWithTax($sst, '100.00', '8.00', '2026-10-02');
    chargeWithTax($sst, '50.00', '4.00', '2026-10-03', discount: '-10.00');
    chargeWithTax($sst, '70.00', '5.60', '2026-09-30');

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-09-01&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['period'], $row['base']])->all() === [['2026-09', '70.00'], ['2026-10', '140.00']])
        ->where('totals.figures.base', '210.00'));
});

test('the CSV has the Base column, blank for a flat tax, and the total of the bases', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    $tourism = Tax::factory()->create(['property_id' => $property->id, 'name' => 'Tourism tax', 'rate_type' => TaxRateType::Flat]);
    chargeWithTax($sst, '100.00', '8.00', '2026-10-09');
    chargeWithTax($tourism, '100.00', '10.00', '2026-10-09');

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/tax/spreadsheet", ['from' => '2026-10-09', 'to' => '2026-10-09', 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows[0])->toBe(['Date / month', 'Tax', 'Lines', 'Base', 'Tax amount'])
        ->and(collect($rows)->slice(1, 2)->pluck(3)->sort()->values()->all())->toBe(['', '100'])
        ->and(end($rows))->toBe(['Total', '', '2', '100', '18']);
});

test('a discount applied later with its own tax line counts once in the base', function () {
    [$user, $property] = reportSetup();
    $sst = Tax::factory()->create(['property_id' => $property->id, 'name' => 'SST']);
    ['charge' => $charge] = chargeWithTax($sst, '100.00', '8.00', '2026-10-09');
    $discount = FolioLine::factory()->create([
        'folio_id' => $charge->folio_id, 'kind' => TransactionKind::Adjustment, 'amount' => '-10.00', 'unit_amount' => '-10.00',
        'posting_date' => '2026-10-09', 'parent_line_id' => $charge->id,
    ]);
    chargeWithTax($sst, '0', '-0.80', '2026-10-09', parent: $discount);

    $this->actingAs($user)->get(taxUrl($property, '?from=2026-10-09&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.base', '90.00')
        ->where('totals.figures.amount', '7.20'));
});
