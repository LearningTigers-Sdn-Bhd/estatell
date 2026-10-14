<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function taxSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function taxUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/tax".$query;
}

function taxLine(Property $property, Tax $tax, string $amount, string $date, TransactionKind $kind = TransactionKind::Tax): FolioLine
{
    $folio = Folio::factory()->create(['property_id' => $property->id]);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $tax->transaction_code_id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
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
    [$front, $frontProperty] = taxSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = taxSetup();
    [, $other] = taxSetup();
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
        ->has('layout.columns', 5)
        ->where('rows.meta.total', 3));
});

test('days list each tax with its tax lines only, reversals included', function () {
    [$user, $property] = taxSetup();
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
    [$user, $property] = taxSetup();
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
    [$user, $property] = taxSetup();

    $this->actingAs($user)->get(taxUrl($property, '?group=week'))->assertSessionHasErrors('group');
});

test('the PDF and CSV keep the group and total only the selected rows', function () {
    [$user, $property] = taxSetup();
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
        ->and($captured->data['breakdown'])->toBeNull();

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/tax/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['Date / month', 'Tax', 'Lines', 'Tax amount'])
        ->and(end($rows))->toBe(['Total', '', '4', '24']);
});
