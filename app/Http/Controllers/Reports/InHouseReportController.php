<?php

namespace App\Http\Controllers\Reports;

use App\Data\Reports\InHouseRow;
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

class InHouseReportController extends Controller
{
    /**
     * The In-house report page: the units that stay on `?date`, sorted and paged on the server.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::InHouse;
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

        return Inertia::render('reports/in-house', [
            'rows' => InHouseRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'currency' => $property->settings?->currency,
        ]);
    }
}
