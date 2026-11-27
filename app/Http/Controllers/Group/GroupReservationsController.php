<?php

namespace App\Http\Controllers\Group;

use App\Data\Group\GroupReservationRowData;
use App\Data\Property\PropertySummaryData;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureGroupAccess;
use App\Http\Requests\Group\GroupReservationsRequest;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Services\Group\GroupReservations;
use App\Services\Group\GroupTableExport;
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Group › Reservations: every property's bookings in one list, and its CSV.
 * See docs/03-staff/05-group-mode.md#reservations.
 */
class GroupReservationsController extends Controller
{
    public function __construct(private GroupReservations $reservations, private PropertyToday $propertyToday) {}

    public function index(GroupReservationsRequest $request, PropertyGroup $propertyGroup): Response
    {
        [$properties, $guests, $leftOut, $filters] = $this->open($request);

        return Inertia::render('group/reservations', [
            'group' => ['id' => $propertyGroup->id, 'name' => $propertyGroup->name],
            'filters' => [...$filters, 'status' => $filters['status']?->value],
            'statuses' => ReservationStatus::options(),
            'properties' => array_values($properties->map(fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property))->all()),
            'leftOut' => $leftOut,
            'reservations' => $this->reservations->page($properties, $guests, $filters),
        ]);
    }

    public function export(GroupReservationsRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): StreamedResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->csv($table['filename'], GroupReservationRowData::csvHeadings(), $table['rows']);
    }

    public function pdf(GroupReservationsRequest $request, PropertyGroup $propertyGroup, GroupTableExport $export): HttpResponse
    {
        $table = $this->table($request, $propertyGroup);

        return $export->pdf($table['filename'], $propertyGroup->name, __('Reservations'), $table['subtitle'], GroupReservationRowData::csvHeadings(), $table['rows']);
    }

    /**
     * Every matching booking, up to `GroupReservations::EXPORT_LIMIT`, and the filters in words for the PDF.
     *
     * @return array{filename: string, subtitle: string, rows: list<list<string|int|null>>}
     */
    private function table(GroupReservationsRequest $request, PropertyGroup $propertyGroup): array
    {
        [$properties, $guests, , $filters] = $this->open($request);
        $rows = array_map(fn (GroupReservationRowData $row): array => $row->csv(), $this->reservations->all($properties, $guests, $filters));
        $parts = array_filter([
            $filters['search'] === null ? null : __('Search ":search"', ['search' => $filters['search']]),
            $filters['status']?->label(),
            match (true) {
                $filters['from'] !== null && $filters['to'] !== null => __('Stays :from – :to', ['from' => $filters['from'], 'to' => $filters['to']]),
                $filters['from'] !== null => __('Stays from :from', ['from' => $filters['from']]),
                $filters['to'] !== null => __('Stays to :to', ['to' => $filters['to']]),
                default => __('Every date'),
            },
            $filters['property'] === null ? null : $properties->firstWhere('code', $filters['property'])?->name,
        ]);

        return [
            'filename' => Str::slug("{$propertyGroup->name} reservations"),
            'subtitle' => implode(' · ', $parts),
            'rows' => $rows,
        ];
    }

    /**
     * @return array{0: Collection<int, Property>, 1: array<int, bool>, 2: list<string>, 3: array{search: ?string, status: ?ReservationStatus, from: ?string, to: ?string, property: ?string}}
     */
    private function open(GroupReservationsRequest $request): array
    {
        /** @var Collection<int, Property> $properties */
        $properties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);
        [$visible, $guests, $leftOut] = $this->reservations->scope($request->user(), $properties);
        $filters = $request->filters($this->propertyToday->date($properties->firstOrFail())->toDateString());

        return [$visible, $guests, $leftOut, $filters];
    }
}
