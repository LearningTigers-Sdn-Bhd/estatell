<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\BoatManifestRow;
use App\Enums\BoatDirection;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Property;
use App\Services\Reports\BoatManifestReport;
use App\Services\Reports\ReportLayoutResolver;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class BoatManifestReportController extends Controller
{
    /**
     * The Boat manifest page: the trips of `?date` and `?direction`, unpaged and in departure order, with a section for
     * each departure.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, BoatManifestReport $manifest, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::BoatManifest;
        $filters = $request->filters();
        $layout = $layoutResolver->resolve($property, $key);
        $allRows = $manifest->rows($property, $filters);
        $rows = new LengthAwarePaginator($allRows, count($allRows), max(1, count($allRows)), 1, ['path' => $request->url()]);
        $hasDepartures = $manifest->hasDepartures($property);
        $canManageSettings = $request->user()->can(PermissionEnum::SettingsManage->value);

        return Inertia::render('reports/boat-manifest', [
            'rows' => BoatManifestRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
            'totals' => $key->totals($allRows),
            'sections' => $key->sections($allRows, $filters),
            'sort' => $key->defaultSort(),
            'layout' => $layout,
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $canManageSettings,
            'from' => $filters->from,
            'to' => $filters->to,
            'maxRangeDays' => $key->maxRangeDays(),
            'currency' => CurrencyData::forProperty($property),
            'direction' => $filters->direction->value ?? BoatDirection::In->value,
            'directionOptions' => BoatDirection::options(),
            'emptyTitle' => $hasDepartures
                ? __('No boat in trips on :date.', ['date' => CarbonImmutable::parse($filters->from)->format('j M')])
                : __('No departures yet'),
            'emptyDescription' => $hasDepartures
                ? __('Pick another date or direction to see the boat trips.')
                : __('Add departures in Settings, Boats.'),
            'settingsUrl' => ! $hasDepartures && $canManageSettings
                ? route('settings.boats.departures.index', ['property' => $property->code], absolute: false)
                : null,
        ]);
    }
}
