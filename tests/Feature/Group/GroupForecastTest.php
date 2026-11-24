<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Services\Group\GroupForecast;
use App\Services\Staff\GroupProperties;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function groupForecastUrl(Property $property, string $query = ''): string
{
    return route('group.forecast', $property->property_group_id).$query;
}

test('the forecast adds the properties up month by month, beside last year', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
    DailyStatistic::factory()->create(['property_id' => $setup['sepilok']->id, 'business_date' => '2025-10-20', 'rooms_available' => 3, 'rooms_sold' => 3]);

    $this->actingAs($setup['manager'])->get(groupForecastUrl($setup['sepilok'], '?from=2026-10&months=3'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/forecast')
            ->where('months', 3)
            ->where('forecast.months', ['2026-10', '2026-11', '2026-12'])
            ->has('forecast.properties', 2)
            ->has('forecast.rows', 9)
            ->where('forecast.rows.6.code', null)
            ->where('forecast.rows.6.month', '2026-10')
            ->where('forecast.rows.6.sold', 4)
            ->where('forecast.rows.6.lastYearSold', 3)
            ->where('forecast.rows.7.lastYearSold', null)
            ->where('forecast.rows.8.sold', 0));
});

test('the forecast opens on this month for six months and takes up to 24', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(groupForecastUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-10')->where('months', 6)->has('forecast.months', 6));

    $this->get(groupForecastUrl($setup['sepilok'], '?months=24'))->assertOk();
    $this->get(groupForecastUrl($setup['sepilok'], '?months=25'))->assertSessionHasErrors('months');
});

test('a property whose role does not show reports is left out and named', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Housekeeping);

    $this->actingAs($setup['manager'])->get(groupForecastUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('forecast.properties', 1)
            ->where('forecast.leftOut', ['Tanini Kinabatangan']));
});

test('the forecast exports every row to CSV', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $csv = $this->actingAs($setup['manager'])
        ->get(route('group.forecast.export', $setup['sepilok']->property_group_id).'?from=2026-10&months=2')
        ->assertOk()
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", $csv)));
    expect($lines)->toHaveCount(7)
        ->and($lines[0])->toStartWith('Month,Property,Basis')
        ->and($lines[5])->toStartWith('2026-10,"All properties"');
});

test('the forecast costs the same queries however many bookings and months', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $count = function (int $months) use ($setup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GroupForecast::class)->for($setup['manager'], app(GroupProperties::class)->propertiesFor($setup['manager'], $setup['sepilok']->group), CarbonImmutable::parse('2026-10-01'), $months);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
    Model::preventLazyLoading();

    try {
        $count(3);
        $few = $count(3);
        groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
        groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
        $many = $count(12);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});
