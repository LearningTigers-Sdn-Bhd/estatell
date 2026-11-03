<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\HouseStatusRow;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class HouseStatusReportController extends Controller
{
    /**
     * The House status page: every room housekeeping needs on `?date`, in its group, unpaged and in group order. The
     * PDF prints the Sand Bay grid. See docs/22-reports/03-house-status-and-items.md#house-status.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::HouseStatus;
        $filters = $request->filters();
        $allRows = $key->report()->rows($property, $filters);
        $rows = new LengthAwarePaginator($allRows, count($allRows), max(1, count($allRows)), 1, ['path' => $request->url()]);

        return Inertia::render('reports/house-status', [
            'rows' => HouseStatusRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sections' => $key->sections($allRows, $filters),
            'sort' => $key->defaultSort(),
            'layout' => $layoutResolver->resolve($property, $key),
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $request->user()->can(PermissionEnum::SettingsManage->value),
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'presets' => $key->datePresets(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }
}
