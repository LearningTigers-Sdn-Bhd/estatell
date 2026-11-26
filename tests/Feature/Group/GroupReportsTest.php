<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Actions\Staff\GrantPropertyAccess;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\GroupReportTab;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\DailyStatistic;
use App\Models\Property;
use App\Models\Role;
use App\Models\TransactionCode;
use App\Services\Group\GroupReports;
use App\Services\Staff\GroupProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function groupReportsUrl(Property $property, string $query = ''): string
{
    return route('group.reports', $property->property_group_id).$query;
}

test('by property adds up each property and works the rates out again from the sums', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    TransactionCode::factory()->for($setup['sepilok'])->system(SystemTransactionCode::RoomRevenue)->create();
    TransactionCode::factory()->for($setup['kinabatangan'])->system(SystemTransactionCode::RoomRevenue)->create();
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupReportsUrl($setup['sepilok'], '?from=2026-10-14&to=2026-10-14'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('group/reports')
            ->where('tab', 'by-property')
            ->has('report.properties', 2)
            ->where('report.leftOut', [])
            ->where('report.figures.0.label', 'Tanini Kinabatangan')
            ->where('report.figures.0.sold', 1)
            ->where('report.figures.1.sold', 2)
            ->where('report.total.label', 'All properties')
            ->where('report.total.available', 6)
            ->where('report.total.sold', 3)
            ->where('report.total.occupancy', '50.00')
            ->where('report.total.roomCharges', '300.00')
            ->where('report.total.adr', '100.00')
            ->where('report.total.revpar', '50.00'));
});

test('a property whose role does not show reports is left out and named', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Housekeeping);

    $this->actingAs($setup['manager'])->get(groupReportsUrl($setup['sepilok']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.properties', 1)
            ->where('report.leftOut', ['Tanini Kinabatangan'])
            ->has('report.figures', 1));
});

test('by date adds the properties up date by date, and the dates are limited', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    DailyStatistic::factory()->create(['property_id' => $setup['sepilok']->id, 'business_date' => '2026-10-13', 'rooms_available' => 3, 'rooms_sold' => 1, 'beds_available' => 0, 'beds_sold' => 0]);
    DailyStatistic::factory()->create(['property_id' => $setup['kinabatangan']->id, 'business_date' => '2026-10-13', 'rooms_available' => 3, 'rooms_sold' => 2, 'beds_available' => 0, 'beds_sold' => 0]);

    $this->actingAs($setup['manager'])->get(groupReportsUrl($setup['sepilok'], '?tab=by-date&from=2026-10-13&to=2026-10-14'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.figures.0.label', '2026-10-13')
            ->where('report.figures.0.available', 6)
            ->where('report.figures.0.sold', 3)
            ->where('report.figures.1.label', '2026-10-14')
            ->has('report.figures', 2));

    $this->get(groupReportsUrl($setup['sepilok'], '?from=2026-01-01&to=2027-06-30'))->assertSessionHasErrors('to');
});

test('bookings lists the changes of every property with the property, and exports to CSV', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);

    $this->actingAs($setup['manager'])->get(groupReportsUrl($setup['sepilok'], '?tab=bookings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('from', '2026-10-14')
            ->has('report.bookings', 2)
            ->where('report.bookings', fn ($rows) => collect($rows)->pluck('propertyName')->sort()->values()->all() === ['Tanini Kinabatangan', 'Tanini Sepilok'])
            ->where('report.bookings.0.canOpen', true));

    $csv = $this->get(route('group.reports.export', $setup['sepilok']->property_group_id).'?tab=by-property&from=2026-10-14&to=2026-10-14')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toStartWith('Property,Available,Sold')
        ->toContain('Tanini Sepilok')
        ->toContain('"All properties",6,2');

    $this->actingAs($setup['frontDesk'])->get(route('group.reports.export', $setup['sepilok']->property_group_id))->assertNotFound();
});

test('group reports cost the same queries however many bookings the properties have', function () {
    $setup = liveGroupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $count = function (GroupReportTab $tab) use ($setup): int {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(GroupReports::class)->for($setup['manager'], app(GroupProperties::class)->propertiesFor($setup['manager'], $setup['sepilok']->group), $tab, new ReportFilters('2026-10-14', '2026-10-20'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
    Model::preventLazyLoading();

    try {
        $count(GroupReportTab::ByProperty);
        $few = [$count(GroupReportTab::ByProperty), $count(GroupReportTab::Bookings)];

        foreach (range(1, 2) as $ignored) {
            groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);
            groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
        }

        $many = [$count(GroupReportTab::ByProperty), $count(GroupReportTab::Bookings)];
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});

test('a booking opens only where the role may view reservations', function () {
    $setup = liveGroupSetup();
    $reportsOnly = Role::query()->create(['property_id' => $setup['kinabatangan']->id, 'name' => 'Reports only']);
    $reportsOnly->givePermissionTo(PermissionEnum::ReportsView->value);
    app(GrantPropertyAccess::class)($setup['kinabatanganOwner'], $setup['kinabatangan'], $setup['manager'], $reportsOnly);
    groupArrival($setup['kinabatangan'], $setup['roomTypes']['kinabatangan']);
    groupArrival($setup['sepilok'], $setup['roomTypes']['sepilok']);

    $this->actingAs($setup['manager'])->get(groupReportsUrl($setup['sepilok'], '?tab=bookings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.bookings', fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [$row['propertyName'] => $row['canOpen']])->all() === ['Tanini Kinabatangan' => false, 'Tanini Sepilok' => true]));
});
