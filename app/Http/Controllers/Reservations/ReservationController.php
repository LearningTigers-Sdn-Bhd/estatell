<?php

namespace App\Http\Controllers\Reservations;

use App\Data\Reservations\ReservationListItemData;
use App\Data\Reservations\ReservationStatusCountData;
use App\Enums\PermissionEnum;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationDirectory;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

class ReservationController extends Controller
{
    /**
     * List the property's reservations with status tabs, search, filters and sorting in the URL.
     */
    public function index(Request $request, Property $property, ReservationDirectory $directory, ReservationRoomBox $roomBox): Response
    {
        $reservations = $directory->paginate($property, $request);
        $page = $reservations->getCollection();
        $settings = $property->settings;
        $boxes = $roomBox->forReservations($page, (bool) $settings?->auto_assign_rooms);
        $repeats = array_flip($directory->repeatReservationIds($property, $page));
        $canViewGuest = $request->user()->can(PermissionEnum::GuestsView->value);

        $reservations->setCollection($page->map(fn (Reservation $reservation): ReservationListItemData => ReservationListItemData::fromModel(
            $reservation,
            $boxes[$reservation->id],
            isset($repeats[$reservation->id]),
            $canViewGuest,
        )));

        return Inertia::render('reservations/index', [
            'reservations' => ReservationListItemData::collect($reservations, PaginatedDataCollection::class),
            'statusCounts' => $this->statusCounts($directory->statusCounts($property, $request)),
            'filters' => [
                'search' => $request->string('filter.search')->toString(),
                'status' => $request->string('filter.status')->toString(),
                'source' => $request->string('filter.source')->toString(),
                'stay' => $directory->stay($property, $request),
            ],
            'sort' => $request->string('sort', 'arrival')->toString(),
            'sorts' => $this->sortOptions(),
            'sources' => ReservationSource::options(),
            'currency' => $settings?->currency,
        ]);
    }

    /**
     * All, then one tab per status, each with its count.
     *
     * @param  array<string, int>  $counts
     * @return list<ReservationStatusCountData>
     */
    private function statusCounts(array $counts): array
    {
        return [
            new ReservationStatusCountData('', __('All'), array_sum($counts)),
            ...array_map(
                fn (ReservationStatus $status): ReservationStatusCountData => new ReservationStatusCountData($status->value, $status->label(), $counts[$status->value] ?? 0),
                ReservationStatus::cases(),
            ),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function sortOptions(): array
    {
        return [
            ['value' => 'arrival', 'label' => __('Arrival, earliest first')],
            ['value' => '-arrival', 'label' => __('Arrival, latest first')],
            ['value' => 'departure', 'label' => __('Departure, earliest first')],
            ['value' => '-departure', 'label' => __('Departure, latest first')],
            ['value' => 'reference', 'label' => __('Reference, A to Z')],
            ['value' => '-reference', 'label' => __('Reference, Z to A')],
            ['value' => 'total', 'label' => __('Total, lowest first')],
            ['value' => '-total', 'label' => __('Total, highest first')],
            ['value' => 'booked', 'label' => __('Booked, oldest first')],
            ['value' => '-booked', 'label' => __('Booked, newest first')],
        ];
    }
}
