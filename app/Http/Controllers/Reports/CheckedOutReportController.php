<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\CheckedOutRow;
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

class CheckedOutReportController extends Controller
{
    /**
     * The Checked out report page: the units that checked out in `?from` to `?to`, sorted and paged on the server.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportPage $reportPage, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::CheckedOut;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);

        [$rows, $sort] = $reportPage->paginate(
            rows: $key->report()->rows($property, $filters),
            sort: $request->string('sort')->toString(),
            defaultSort: $key->defaultSort(),
            columnKeys: $layout->visibleKeys(),
            page: max(1, $request->integer('page', 1)),
            path: $request->url(),
        );

        return Inertia::render('reports/checked-out', [
            'rows' => CheckedOutRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'sort' => $sort,
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }
}
