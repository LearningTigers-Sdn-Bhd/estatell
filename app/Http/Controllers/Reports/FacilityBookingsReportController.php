<?php

namespace App\Http\Controllers\Reports;

use App\Data\Property\CurrencyData;
use App\Data\Reports\FacilityBookingRow;
use App\Enums\FacilityBookingStatus;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFiltersRequest;
use App\Models\Facility;
use App\Models\Property;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class FacilityBookingsReportController extends Controller
{
    /**
     * The Facility bookings page: the bookings from `?from` to `?to`, unpaged, in sections of one facility, date and
     * time. `facilities` and `statuses` are the picked filters; empty statuses mean Tentative and Confirmed. See
     * docs/33-facilities/05-timeline-and-report.md#facility-bookings-report.
     */
    public function __invoke(ReportFiltersRequest $request, Property $property, ReportLayoutResolver $layoutResolver): Response
    {
        $key = ReportKey::FacilityBookings;
        $filters = $request->filters();
        $allRows = $key->report()->rows($property, $filters);
        $rows = new LengthAwarePaginator($allRows, count($allRows), max(1, count($allRows)), 1, ['path' => $request->url()]);

        return Inertia::render('reports/facility-bookings', [
            'rows' => FacilityBookingRow::collect($rows->withQueryString(), PaginatedDataCollection::class),
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
            'facilities' => array_map(strval(...), $filters->facilityIds),
            'facilityOptions' => Facility::query()->where('property_id', $property->id)->ordered()->get(['id', 'name'])
                ->map(fn (Facility $facility): array => ['value' => (string) $facility->id, 'label' => $facility->name])->values()->all(),
            'statuses' => $filters->facilityStatuses === [] ? [FacilityBookingStatus::Tentative->value, FacilityBookingStatus::Confirmed->value] : $filters->facilityStatuses,
            'statusOptions' => FacilityBookingStatus::options(),
        ]);
    }
}
