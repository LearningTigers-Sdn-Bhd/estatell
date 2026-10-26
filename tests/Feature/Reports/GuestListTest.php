<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function guestListUnit(Property $property, string $arrival, string $departure, UnitStatus $status = UnitStatus::Confirmed, array $attributes = []): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'status' => $status,
        ...$attributes,
    ]);
}

function guestListUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/guest-list".$query;
}

/**
 * @return array<string, int>
 */
function guestListCounts(Assert $page): array
{
    return collect($page->toArray()['props']['tabs'])->pluck('count', 'value')->all();
}

test('a bad or missing tab opens Arrivals', function (?string $tab) {
    [$user, $property] = reportSetup();
    guestListUnit($property, '2026-10-10', '2026-10-12');

    $this->actingAs($user)->get(guestListUrl($property, $tab === null ? '' : "?tab={$tab}"))->assertInertia(fn (Assert $page) => $page
        ->component('reports/guest-list')
        ->where('tab', 'arrivals')
        ->has('rows.data', 1)
        ->has('rows.data.0.arrival'));
})->with([null, 'nope', 'revenue']);

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager] = reportSetup();
    [, $other] = reportSetup();

    $this->actingAs($front)->get(guestListUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(guestListUrl($other))->assertNotFound();
});

test('the counts follow the dates and the property', function () {
    [$user, $property] = reportSetup();
    [, $other] = reportSetup();
    guestListUnit($property, '2026-10-10', '2026-10-12');
    guestListUnit($property, '2026-10-11', '2026-10-13');
    guestListUnit($property, '2026-10-08', '2026-10-10', UnitStatus::CheckedOut, ['checked_in_at' => now()->subDays(2), 'checked_out_at' => now()]);
    guestListUnit($property, '2026-10-08', '2026-10-11', UnitStatus::CheckedIn, ['checked_in_at' => now()->subDays(2)]);
    guestListUnit($property, '2026-10-10', '2026-10-12', UnitStatus::Cancelled);
    guestListUnit($other, '2026-10-10', '2026-10-12');

    $this->actingAs($user)->get(guestListUrl($property, '?from=2026-10-10&to=2026-10-10'))->assertInertia(fn (Assert $page) => expect(guestListCounts($page))->toBe([
        'arrivals' => 1,
        'in-house' => 1,
        'departures' => 1,
        'checked-out' => 1,
    ]));

    $this->actingAs($user)->get(guestListUrl($property, '?from=2026-10-10&to=2026-10-11'))->assertInertia(fn (Assert $page) => expect(guestListCounts($page))->toMatchArray(['arrivals' => 2, 'departures' => 2, 'checked-out' => 1]));
});

test('in-house counts the one date and the tab links carry the dates', function () {
    [$user, $property] = reportSetup();
    $base = "/p/{$property->code}/reports/guest-list";

    $this->actingAs($user)->get(guestListUrl($property, '?tab=arrivals&from=2026-10-10&to=2026-10-14'))->assertInertia(fn (Assert $page) => $page
        ->where('tabs', fn ($tabs) => collect($tabs)->pluck('href', 'value')->all() === [
            'arrivals' => "{$base}?tab=arrivals&from=2026-10-10&to=2026-10-14",
            'in-house' => "{$base}?tab=in-house&date=2026-10-10",
            'departures' => "{$base}?tab=departures&from=2026-10-10&to=2026-10-14",
            'checked-out' => "{$base}?tab=checked-out&from=2026-10-10&to=2026-10-14",
        ]));

    $this->actingAs($user)->get(guestListUrl($property, '?tab=in-house&date=2026-10-12'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-12')
        ->where('to', '2026-10-12')
        ->where('maxRangeDays', null)
        ->has('totals')
        ->where('tabs', fn ($tabs) => collect($tabs)->pluck('href', 'value')->all() === [
            'arrivals' => "{$base}?tab=arrivals&from=2026-10-12&to=2026-10-12",
            'in-house' => "{$base}?tab=in-house&date=2026-10-12",
            'departures' => "{$base}?tab=departures&from=2026-10-12&to=2026-10-12",
            'checked-out' => "{$base}?tab=checked-out&from=2026-10-12&to=2026-10-12",
        ]));
});

test('only the open tab sends rows', function () {
    [$user, $property] = reportSetup();
    $arriving = guestListUnit($property, '2026-10-10', '2026-10-12');
    $leaving = guestListUnit($property, '2026-10-08', '2026-10-10');
    $out = guestListUnit($property, '2026-10-08', '2026-10-10', UnitStatus::CheckedOut, ['checked_in_at' => now()->subDays(2), 'checked_out_at' => now()]);

    $keys = fn (string $tab) => $this->actingAs($user)->get(guestListUrl($property, "?tab={$tab}&from=2026-10-10&to=2026-10-10"))->viewData('page')['props']['rows']['data'];

    expect(collect($keys('arrivals'))->pluck('key')->all())->toBe([$arriving->id])
        ->and(collect($keys('departures'))->pluck('key')->sort()->values()->all())->toBe([$leaving->id, $out->id])
        ->and(collect($keys('checked-out'))->pluck('key')->all())->toBe([$out->id]);
});

test('each tab uses the layout of its own report', function () {
    [$user, $property] = reportSetup();
    $columns = array_map(fn ($column): array => ['key' => $column->key, 'visible' => $column->key !== 'balance'], ReportKey::CheckedOut->columns());

    $this->actingAs($user)->put("/p/{$property->code}/reports/checked-out/layout", ['orientation' => 'landscape', 'columns' => $columns])->assertRedirect();

    $balanceVisible = fn (string $tab) => $this->actingAs($user)->get(guestListUrl($property, "?tab={$tab}"))->viewData('page')['props']['layout']['columns'];
    $visible = fn (array $layout) => collect($layout)->firstWhere('key', 'balance')['visible'];

    expect($visible($balanceVisible('checked-out')))->toBeFalse()
        ->and($visible($balanceVisible('departures')))->toBeTrue();
    $this->actingAs($user)->get(guestListUrl($property, '?tab=checked-out'))->assertInertia(fn (Assert $page) => $page
        ->where('layout.orientation', 'landscape')
        ->has('layout.columns', 14));
});

test('the old URLs redirect to the tab with the query', function (string $slug) {
    [$user, $property] = reportSetup();
    $old = "/p/{$property->code}/reports/{$slug}";

    $this->actingAs($user)->get("{$old}?from=2026-10-01&to=2026-10-07&sort=-guest")
        ->assertRedirect(guestListUrl($property, "?from=2026-10-01&to=2026-10-07&sort=-guest&tab={$slug}"));
    $this->actingAs($user)->get($old)->assertRedirect(guestListUrl($property, "?tab={$slug}"));
})->with(['arrivals', 'departures', 'in-house', 'checked-out']);

test('the old URL of In-house keeps its date', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get("/p/{$property->code}/reports/in-house?date=2026-10-12")
        ->assertRedirect(guestListUrl($property, '?date=2026-10-12&tab=in-house'));
});

test('exports and layouts still work on the report URL of a tab', function () {
    [$user, $property] = reportSetup();
    guestListUnit($property, '2026-10-10', '2026-10-12');

    $this->actingAs($user)->post("/p/{$property->code}/reports/arrivals/spreadsheet", ['format' => 'csv', 'from' => '2026-10-10', 'to' => '2026-10-10'])->assertOk();
    $this->actingAs($user)->post("/p/{$property->code}/reports/in-house/pdf", ['date' => '2026-10-10'])->assertOk();
    $this->actingAs($user)->delete("/p/{$property->code}/reports/departures/layout")->assertRedirect();
});
