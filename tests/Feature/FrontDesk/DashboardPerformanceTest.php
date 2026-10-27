<?php

use App\Enums\PermissionEnum;
use App\Models\BusinessDate;
use App\Models\DailyStatistic;
use App\Models\NightAudit;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/DashboardSetup.php';

function performanceUrl(string $code, string $query = ''): string
{
    return route('dashboard', $code).'?tab=performance'.$query;
}

test('without reports.view the dashboard is Today, even for ?tab=performance', function () {
    $setup = dashboardSetup([PermissionEnum::ReservationsView]);

    $this->actingAs($setup['user'])->get(performanceUrl($setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'today')
            ->where('can.canViewReports', false)
            ->where('period', null)
            ->where('performance', null)
            ->missing('tiles'));
});

test('with reports.view the default tab is Performance and Today props are not sent', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'performance')
            ->where('can.canViewReports', true)
            ->has('period')
            ->where('tiles', null));
});

test('?tab=today opens Today for a user with reports.view', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code).'?tab=today')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'today')
            ->where('period', null)
            ->where('performance', null)
            ->missing('tiles'));
});

test('an unknown tab is Performance', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code).'?tab=money')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'performance'));
});

test('the Performance tab sends only its own props, and the figures are deferred', function () {
    $setup = dashboardSetup([PermissionEnum::ReservationsView, PermissionEnum::ReportsView, PermissionEnum::StaffManage, PermissionEnum::SettingsManage, PermissionEnum::HousekeepingView]);
    dashboardUnit($setup['property'], '2026-10-10', '2026-10-12');

    $this->actingAs($setup['user'])->get(performanceUrl($setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'performance')
            ->where('period.preset', 'month')
            ->where('period.from', '2026-10-01')
            ->where('period.to', '2026-10-12')
            ->where('period.previousFrom', '2026-09-01')
            ->where('period.previousTo', '2026-09-12')
            ->where('period.groupBy', 'day')
            ->has('period.presets', 5)
            ->where('tiles', null)
            ->where('todo', null)
            ->where('housekeeping', null)
            ->where('recommended', null)
            ->where('youreLive', null)
            ->missing('performance')
            ->loadDeferredProps('performance', fn (Assert $page) => $page
                ->where('performance.hasStays', true)
                ->where('performance.channelMix.nights', 2)
                ->has('performance.trend', 12)
                ->has('performance.leadTime.buckets', 4)));
});

test('Today keeps the You are live card and the Today props, the Performance tab does not', function () {
    $setup = dashboardSetup([PermissionEnum::ReservationsView, PermissionEnum::ReportsView, PermissionEnum::StaffManage]);

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code).'?tab=today')
        ->assertInertia(fn (Assert $page) => $page->whereNot('youreLive', null));

    $this->actingAs($setup['user'])->get(performanceUrl($setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('youreLive', null));
});

test('the night audit alert shows on both tabs', function () {
    $setup = dashboardSetup();
    $businessDate = BusinessDate::query()->where('property_id', $setup['property']->id)->firstOrFail();
    $businessDate->update(['status' => 'auditing']);
    NightAudit::factory()->failed()->create(['business_date_id' => $businessDate->id]);
    $message = 'Night audit for 12 Oct did not finish. The business date did not change.';

    $this->actingAs($setup['user'])->get(route('dashboard', $setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert.message', $message));
    $this->actingAs($setup['user'])->get(performanceUrl($setup['property']->code))
        ->assertInertia(fn (Assert $page) => $page->where('alert.message', $message));
});

test('a preset in the URL sets the period', function () {
    $setup = dashboardSetup();

    $this->actingAs($setup['user'])->get(performanceUrl($setup['property']->code, '&period=12m'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', '12m')
            ->where('period.groupBy', 'month')
            ->where('period.from', '2025-11-01')
            ->where('period.previousFrom', '2024-11-01')
            ->where('period.previousTo', '2025-10-12'));
});

test('a picked month works, and a month outside the data falls back to this month', function () {
    $setup = dashboardSetup();
    DailyStatistic::factory()->create(['property_id' => $setup['property']->id, 'business_date' => '2026-08-15']);
    $code = $setup['property']->code;

    $this->actingAs($setup['user'])->get(performanceUrl($code, '&month=2026-09'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', null)
            ->where('period.month', '2026-09')
            ->where('period.from', '2026-09-01')
            ->where('period.to', '2026-09-30')
            ->where('period.months', ['2026-09', '2026-08']));

    foreach (['2026-07', '2026-10', '2026-11', 'september'] as $month) {
        $this->actingAs($setup['user'])->get(performanceUrl($code, "&month={$month}"))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.preset', 'month')
                ->where('period.month', null)
                ->where('period.from', '2026-10-01'));
    }
});

test('the performance tab is scoped to the property', function () {
    $setup = dashboardSetup();
    $other = dashboardSetup();

    $this->actingAs($setup['user'])->get(performanceUrl($other['property']->code))->assertNotFound();
});
