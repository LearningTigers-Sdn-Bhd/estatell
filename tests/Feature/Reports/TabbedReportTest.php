<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\TabbedReport;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use Illuminate\Routing\Route;

function tabbedReportRequest(string $routeName, string $query = ''): ReportFiltersRequest
{
    $request = ReportFiltersRequest::create('/reports'.$query);
    $route = (new Route('GET', 'reports', []))->name($routeName);
    $route->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    return $request;
}

test('each tab maps to its report and a bad or missing tab opens the first one', function (TabbedReport $page, ?string $tab, ReportKey $key) {
    expect($page->reportKey($tab))->toBe($key);
})->with([
    [TabbedReport::GuestList, 'in-house', ReportKey::InHouse],
    [TabbedReport::GuestList, 'checked-out', ReportKey::CheckedOut],
    [TabbedReport::GuestList, 'nope', ReportKey::Arrivals],
    [TabbedReport::GuestList, null, ReportKey::Arrivals],
    [TabbedReport::DailyReport, 'charges', ReportKey::DailyCharges],
    [TabbedReport::DailyReport, 'cashier', ReportKey::Payments],
    [TabbedReport::DailyReport, null, ReportKey::DailyRevenue],
]);

test('the report filters take the report of the tab on a tabbed page', function () {
    expect(tabbedReportRequest('reports.guest-list', '?tab=departures')->reportKey())->toBe(ReportKey::Departures)
        ->and(tabbedReportRequest('reports.guest-list')->reportKey())->toBe(ReportKey::Arrivals)
        ->and(tabbedReportRequest('reports.daily-report', '?tab=cashier')->reportKey())->toBe(ReportKey::Payments)
        ->and(tabbedReportRequest('reports.daily-report', '?tab=charges')->reportKey())->toBe(ReportKey::DailyCharges)
        ->and(tabbedReportRequest('reports.daily-report', '?tab[]=x')->reportKey())->toBe(ReportKey::DailyRevenue)
        ->and(tabbedReportRequest('reports.tax')->reportKey())->toBe(ReportKey::Tax);
});

test('the sidebar entry is active on the page and on the old pages of its unlisted tabs', function () {
    expect(TabbedReport::GuestList->activeRouteNames())->toBe(['reports.guest-list', 'reports.arrivals', 'reports.in-house', 'reports.departures', 'reports.checked-out'])
        ->and(TabbedReport::DailyReport->activeRouteNames())->toBe(['reports.daily-report', 'reports.daily-revenue', 'reports.daily-charges']);
});

test('the pages need reports.view and the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager] = reportSetup();

    $this->actingAs($front)->get("/p/{$frontProperty->code}/reports/guest-list")->assertForbidden();
    $this->actingAs($manager)->get('/p/'.Property::factory()->create()->code.'/reports/daily-report')->assertNotFound();
});
