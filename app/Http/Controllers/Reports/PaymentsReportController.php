<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\PaymentRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportGroup;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\PaymentsReport;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PaymentsReportController extends Controller
{
    /**
     * The Payments report page: the folio and city ledger payments and refunds from `?from` to `?to`, sorted and paged on the server, with the summary by method or by staff.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver, PaymentsReport $report): Response
    {
        $key = ReportKey::Payments;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $report->rows($property, $filters);

        [$rows, $sort] = $reportPage->paginate(
            rows: $allRows,
            sort: $request->string('sort')->toString(),
            defaultSort: $key->defaultSort($filters->group),
            columnKeys: $layout->visibleKeys(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
        );

        return Inertia::render('reports/payments', [
            'rows' => PaymentRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'breakdown' => $key->breakdown($property, $allRows, $filters),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'group' => $filters->group,
            'groupOptions' => ReportGroup::options($key->groups()),
            'staff' => $filters->staff === null ? '' : (string) $filters->staff,
            'staffOptions' => $report->staffOptions($property, $filters),
            'refundsOnly' => $filters->refundsOnly,
            'currency' => CurrencyData::forProperty($property),
        ]);
    }
}
