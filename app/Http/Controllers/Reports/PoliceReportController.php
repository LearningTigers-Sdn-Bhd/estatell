<?php

namespace App\Http\Controllers\Reports;

use App\Data\Reports\PoliceReportRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class PoliceReportController extends Controller
{
    /**
     * The Police report page: the guests of the units that stayed from `?from` to `?to`, sorted and paged on the server. `?foreign=1` keeps the
     * foreign guests.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::PoliceReport;
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

        return Inertia::render('reports/police-report', [
            'rows' => PoliceReportRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'foreignOnly' => $filters->foreignOnly,
            'currency' => $property->settings?->currency,
        ]);
    }
}
