<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\HousekeepingItemRow;
use App\Data\Reports\HousekeepingLogRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\TabbedReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class HousekeepingReportController extends Controller
{
    /**
     * The Housekeeping report page: the open tab (`?tab`, Items when missing or wrong) as its own report. Items is one
     * date (`?date`), Log a range (`?from`, `?to`). Only the open tab's rows are sent. See
     * docs/22-reports/03-house-status-and-items.md#housekeeping-page.
     */
    public function show(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver): Response
    {
        $page = TabbedReport::Housekeeping;
        $requested = $request->query('tab');
        $tab = $page->tab(is_string($requested) ? $requested : null);
        $key = $page->reportKey($tab);
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $key->report()->rows($property, $filters);

        [$rows, $sort] = $reportPage->paginate(
            rows: $allRows,
            sort: $request->string('sort')->toString(),
            defaultSort: $key->defaultSort(),
            columnKeys: $layout->visibleKeys(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
        );

        $rowClass = $key === ReportKey::HousekeepingLog ? HousekeepingLogRow::class : HousekeepingItemRow::class;

        return Inertia::render('reports/housekeeping', [
            'tab' => $tab,
            'tabs' => $this->tabs($property, $filters->from, $filters->to),
            'rows' => $rowClass::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'presets' => $key->datePresets(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * The old Housekeeping items page: opens the Items tab, keeping the query.
     */
    public function items(Request $request, Property $property): RedirectResponse
    {
        return $this->redirectToTab($request, $property, 'items');
    }

    /**
     * The old Housekeeping log page: opens the Log tab, keeping the query.
     */
    public function log(Request $request, Property $property): RedirectResponse
    {
        return $this->redirectToTab($request, $property, 'log');
    }

    private function redirectToTab(Request $request, Property $property, string $tab): RedirectResponse
    {
        return redirect()->route('reports.housekeeping', [...$request->query(), 'property' => $property->code, 'tab' => $tab]);
    }

    /**
     * Each tab with its label and link. Items links with `date`, the first date of the range; Log with `from` and `to`.
     *
     * @return list<array{value: string, label: string, href: string}>
     */
    private function tabs(Property $property, string $from, string $to): array
    {
        $tabs = [];

        foreach (TabbedReport::Housekeeping->tabs() as $tab => $key) {
            $dates = $key->maxRangeDays() === null ? ['date' => $from] : ['from' => $from, 'to' => $to];

            $tabs[] = [
                'value' => $tab,
                'label' => $tab === 'items' ? __('Items') : __('Log'),
                'href' => route('reports.housekeeping', ['property' => $property->code, 'tab' => $tab, ...$dates], absolute: false),
            ];
        }

        return $tabs;
    }
}
