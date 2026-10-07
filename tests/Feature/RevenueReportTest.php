<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function revenueSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function revenueLine(Property $property, TransactionCode $code, string $amount, string $date, TransactionKind $kind = TransactionKind::Charge): FolioLine
{
    $folio = Folio::factory()->create(['property_id' => $property->id]);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
    ]);
}

function revenueUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/revenue".$query;
}

/**
 * Two codes with lines on three dates in two months, and a payment that is not revenue.
 *
 * @return array{0: TransactionCode, 1: TransactionCode}
 */
function revenueLines(Property $property): array
{
    $room = TransactionCode::factory()->for($property)->create(['code' => 'ROOM', 'name' => 'Room revenue']);
    $meal = TransactionCode::factory()->for($property)->create(['code' => 'MEAL', 'name' => 'Meals']);
    revenueLine($property, $room, '100.00', '2026-09-30');
    revenueLine($property, $room, '200.00', '2026-10-09');
    revenueLine($property, $room, '50.00', '2026-10-09');
    revenueLine($property, $meal, '30.00', '2026-10-09');
    revenueLine($property, $room, '120.00', '2026-10-10');
    revenueLine($property, $room, '-300.00', '2026-10-10', TransactionKind::Payment);

    return [$room, $meal];
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = revenueSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = revenueSetup();
    [, $other] = revenueSetup();
    revenueLines($own);
    revenueLines($other);

    $this->actingAs($front)->get(revenueUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(revenueUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(revenueUrl($own, '?from=2026-10-09&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/revenue')
        ->where('maxRangeDays', 366)
        ->where('group', 'day')
        ->where('sort', 'period')
        ->has('groupOptions', 2)
        ->has('layout.columns', 5)
        ->where('rows.meta.total', 3));
});

test('days are listed by date and code, live for the open date, without payments and with stable keys', function () {
    [$user, $property] = revenueSetup();
    [$room, $meal] = revenueLines($property);

    $this->actingAs($user)->get(revenueUrl($property, '?from=2026-10-09&to=2026-10-10&sort=amount'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 3)
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['code'], $row['description'], $row['lines'], $row['amount']])->all() === [
            ["2026-10-09:{$meal->id}", 'MEAL', 'Meals', 1, '30.00'],
            ["2026-10-10:{$room->id}", 'ROOM', 'Room revenue', 1, '120.00'],
            ["2026-10-09:{$room->id}", 'ROOM', 'Room revenue', 2, '250.00'],
        ])
        ->where('totals.figures.lines', 4)
        ->where('totals.figures.amount', '400.00'));
});

test('a month groups its days by code and keeps only the dates in the range', function () {
    [$user, $property] = revenueSetup();
    [$room, $meal] = revenueLines($property);

    $this->actingAs($user)->get(revenueUrl($property, '?from=2026-09-01&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('group', 'month')
        ->where('rows.meta.total', 3)
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['lines'], $row['amount']])->all() === [
            ["2026-09:{$room->id}", 1, '100.00'],
            ["2026-10:{$room->id}", 3, '370.00'],
            ["2026-10:{$meal->id}", 1, '30.00'],
        ])
        ->where('totals.figures.amount', '500.00'));

    $this->actingAs($user)->get(revenueUrl($property, '?from=2026-10-09&to=2026-10-31&group=month'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 2)
        ->where('totals.figures.amount', '400.00'));
});

test('the group must be day or month', function () {
    [$user, $property] = revenueSetup();

    $this->actingAs($user)->get(revenueUrl($property, '?group=week'))->assertSessionHasErrors('group');
    $this->actingAs($user)->postJson("/p/{$property->code}/reports/revenue/pdf", ['group' => 'week'])->assertJsonValidationErrors('group');
});

test('other reports ignore the group', function () {
    [$user, $property] = revenueSetup();

    $this->actingAs($user)->get("/p/{$property->code}/reports/in-house?group=week")->assertOk();
});

test('the PDF and CSV keep the group and end with the total row of the selected rows', function () {
    [$user, $property] = revenueSetup();
    [$room] = revenueLines($property);
    $base = ['from' => '2026-09-01', 'to' => '2026-10-31', 'group' => 'month', 'rowKeys' => ["2026-09:{$room->id}", "2026-10:{$room->id}"]];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/revenue/pdf", $base)->assertOk();
    expect($captured->data['group'])->toBe('Month')
        ->and($captured->data['rows'])->toHaveCount(2)
        ->and($captured->data['rows'][0]->cells()['period'])->toBe('Sep 2026')
        ->and($captured->data['totals']->figures['amount'])->toBe('470.00');

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/revenue/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(4)
        ->and($rows[1][0])->toBe('2026-09')
        ->and($rows[3])->toBe(['Total', '', '', '4', '470']);
});

test('hidden columns stay hidden in the totals', function () {
    [$user, $property] = revenueSetup();
    revenueLines($property);
    $layout = ReportLayout::factory()->create(['property_id' => $property->id, 'report_key' => 'revenue']);

    foreach ([['amount', true], ['lines', false], ['period', true], ['code', false], ['description', false]] as $position => [$key, $visible]) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position, 'is_visible' => $visible]);
    }

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/revenue/spreadsheet", ['from' => '2026-10-09', 'to' => '2026-10-09', 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows[0])->toBe(['Amount', 'Date / month'])
        ->and(end($rows))->toBe(['280', 'Total']);
});

test('the sidebar lists Occupancy and Revenue under the Revenue category', function () {
    [$user, $property] = revenueSetup();

    $this->actingAs($user)->get(revenueUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.2.groups', fn ($groups) => collect($groups)->firstWhere('label', 'Revenue') !== null
            && collect(collect($groups)->firstWhere('label', 'Revenue')['items'])->pluck('title')->all() === ['Occupancy', 'Revenue']));
});
