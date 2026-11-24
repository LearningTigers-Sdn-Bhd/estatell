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
use App\Services\Property\PropertyToday;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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

    public function export(GroupReservationsRequest $request, PropertyGroup $propertyGroup): StreamedResponse
    {
        [$properties, $guests, , $filters] = $this->open($request);
        $rows = $this->reservations->all($properties, $guests, $filters);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                throw new RuntimeException('The CSV output stream could not be opened.');
            }

            fputcsv($out, GroupReservationRowData::csvHeadings());

            foreach ($rows as $row) {
                fputcsv($out, $row->csv());
            }

            fclose($out);
        }, Str::slug("{$propertyGroup->name} reservations").'.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store, private']);
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
