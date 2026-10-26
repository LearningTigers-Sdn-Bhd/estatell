<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\DailyChargeRow;
use App\Data\Reports\DailyRevenueReportRow;
use App\Data\Reports\ReportFilters;
use App\Enums\ChargeBucket;
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
     * sends the cards only, Revenue and Charges their own reports (Charges also its bucket and search filters), and
     * Cashier activity the Payments report with the Staff summary as the default.
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
            'tabs' => $this->tabs($property, $filters),
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

        $isCharges = $tab === 'charges';

        return Inertia::render('reports/daily-report', [
            ...$props,
            ...($isCharges ? [
                'bucket' => $filters->bucket,
                'bucketOptions' => ChargeBucket::options(),
                'search' => $filters->search ?? '',
            ] : []),
            'rows' => $isCharges
                ? DailyChargeRow::collect($rows->withQueryString(), PaginatedDataCollection::class)
                : DailyRevenueReportRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
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
     * Each tab with its label and link, keeping the dates. The Charges tab takes 31 days at most, so its link keeps
     * `to` and moves `from` back when the range is longer.
     *
     * @return list<array{value: string, label: string, href: string}>
     */
    private function tabs(Property $property, ReportFilters $filters): array
    {
        $labels = ['overview' => __('Overview'), 'revenue' => __('Revenue'), 'charges' => __('Charges'), 'cashier' => __('Cashier activity')];
        $tabs = [];

        foreach (TabbedReport::DailyReport->tabs() as $tab => $key) {
            $from = $key->maxRangeDays() === null ? $filters->from : $filters->clampedFrom($key->maxRangeDays());
            $tabs[] = [
                'value' => $tab,
                'label' => $labels[$tab],
                'href' => route('reports.daily-report', ['property' => $property->code, 'tab' => $tab, 'from' => $from, 'to' => $filters->to], absolute: false),
            ];
        }

        return $tabs;
    }
}
