<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Http\Requests\Group\GroupPropertyReportRequest;
use App\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;

function groupPropertyReportUrl(Property $property, string $query = ''): string
{
    return route('group.property-reports', $property->property_group_id).$query;
}

test('a property report runs for every property, each row with its property', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupPropertyReportUrl($setup['sepilok'], '?report=arrivals&from=2026-10-14&to=2026-10-14'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/property-reports')
            ->where('result.report', 'arrivals')
            ->where('result.rowCount', 3)
            ->has('result.rows', 3)
            ->where('result.leftOut', [])
            ->where('oneDate', false)
            ->where('maxRangeDays', 31)
            ->has('reports'));

    $codes = collect($this->get(groupPropertyReportUrl($setup['sepilok'], '?report=arrivals&from=2026-10-14&to=2026-10-14'))->inertiaProps('result.rows'))->pluck('code')->countBy()->sortKeys()->all();
    expect($codes)->toBe(collect([$setup['sepilok']->code => 2, $setup['kinabatangan']->code => 1])->sortKeys()->all());
});

test('a report of one date takes a single date, and a report that groups takes its group', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(groupPropertyReportUrl($setup['sepilok'], '?report=in-house&date=2026-10-14'))
        ->assertInertia(fn (Assert $page) => $page->where('oneDate', true)->where('filters.from', '2026-10-14')->where('filters.to', '2026-10-14'));

    $this->get(groupPropertyReportUrl($setup['sepilok'], '?report=revenue'))
        ->assertInertia(fn (Assert $page) => $page->where('filters.group', 'day')->has('groups', 2));
});

test('only the reports group mode can run are offered, and the dates follow the report\'s limit', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(groupPropertyReportUrl($setup['sepilok'], '?report=police-report'))->assertSessionHasErrors('report');
    $this->get(groupPropertyReportUrl($setup['sepilok'], '?report=arrivals&from=2026-10-01&to=2026-12-01'))->assertSessionHasErrors('to');
});

test('a property whose role does not show reports is left out and named', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Housekeeping);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupPropertyReportUrl($setup['sepilok'], '?report=arrivals&from=2026-10-14&to=2026-10-14'))
        ->assertInertia(fn (Assert $page) => $page->where('result.rowCount', 0)->where('result.leftOut', ['Tanini Kinabatangan']));
});

test('the CSV has every row with its property', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $csv = $this->actingAs($setup['manager'])
        ->get(route('group.property-reports.export', $setup['sepilok']->property_group_id).'?report=arrivals&from=2026-10-14&to=2026-10-14')
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", $csv)));
    expect($lines[0])->toStartWith('Property,')
        ->and($csv)->toContain('Tanini Sepilok')
        ->and($csv)->toContain('Tanini Kinabatangan');
});

test('every report group mode offers runs for the group with its default dates', function (string $report) {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupPropertyReportUrl($setup['sepilok'], "?report={$report}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('result.report', $report));
})->with(fn (): array => array_map(fn ($key): string => $key->value, GroupPropertyReportRequest::reports()));
