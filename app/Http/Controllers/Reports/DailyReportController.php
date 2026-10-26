<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\DailyRevenueReportRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportGroup;
use App\Enums\TabbedReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\DailyOverview;
use App\Services\Reports\PaymentsReportPage;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class DailyReportController extends Controller
{
    /**
     * The Daily report page: the open tab (`?tab`, Overview when missing or wrong) for the chosen dates. Overview
     * sends the cards only, Revenue its own report, and Cashier activity the Payments report with the Staff summary as
     * the default.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver, DailyOverview $overview, PaymentsReportPage $payments): Response
    {
        $page = TabbedReport::DailyReport;
        $requested = $request->query('tab');
        $tab = $page->tab(is_string($requested) ? $requested : null);
        $key = $page->reportKey($tab);
        $filters = $request->filters();
        $canEditLayout = $request->user()->can(PermissionEnum::SettingsManage->value);

        $props = [
            'tab' => $tab,
            'tabs' => $this->tabs($property, $filters->from, $filters->to),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'currency' => CurrencyData::forProperty($property),
        ];

        if ($tab === 'overview') {
            return Inertia::render('reports/daily-report', [...$props, 'overview' => $overview->forDates($property, $filters)]);
        }

        if ($tab === 'cashier') {
            if (! $request->has('group')) {
                $filters->group = ReportGroup::Staff;
            }

            return Inertia::render('reports/daily-report', [...$props, ...$payments->props(
                property: $property,
                filters: $filters,
                sort: $request->string('sort')->toString(),
                page: max(1, $request->integer('page', 1)),
                path: $request->url(),
                canEditLayout: $canEditLayout,
            )]);
        }

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

        return Inertia::render('reports/daily-report', [
            ...$props,
            'rows' => DailyRevenueReportRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $canEditLayout,
            'group' => $filters->group,
            'groupOptions' => ReportGroup::options($key->groups()),
        ]);
    }

    /**
     * Each tab with its label and link, keeping the dates.
     *
     * @return list<array{value: string, label: string, href: string}>
     */
    private function tabs(Property $property, string $from, string $to): array
    {
        $labels = ['overview' => __('Overview'), 'revenue' => __('Revenue'), 'cashier' => __('Cashier activity')];
        $tabs = [];

        foreach (array_keys(TabbedReport::DailyReport->tabs()) as $tab) {
            $tabs[] = [
                'value' => $tab,
                'label' => $labels[$tab],
                'href' => route('reports.daily-report', ['property' => $property->code, 'tab' => $tab, 'from' => $from, 'to' => $to], absolute: false),
            ];
        }

        return $tabs;
    }
}
