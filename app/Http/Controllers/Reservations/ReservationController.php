<?php

namespace App\Http\Controllers\Reservations;

use App\Data\Reservations\ReservationActivityData;
use App\Data\Reservations\ReservationAddOnData;
use App\Data\Reservations\ReservationListItemData;
use App\Data\Reservations\ReservationStatusCountData;
use App\Data\Reservations\ReservationUnitData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\BillingMode;
use App\Enums\PermissionEnum;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Folio\FolioStatement;
use App\Services\Reservations\ReservationActivityLog;
use App\Services\Reservations\ReservationDirectory;
use App\Services\Reservations\ReservationFormOptions;
use App\Services\Reservations\ReservationGuestRows;
use App\Services\Reservations\ReservationOverview;
use App\Services\Reservations\ReservationRoomBox;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'hasReservations' => $property->reservations()->exists(),
            'canManage' => $request->user()->can(PermissionEnum::ReservationsManage->value),
        ]);
    }

    /**
     * Reservation page, Units tab. `?unit=` picks the unit panel, and an unknown unit falls back to the first.
     */
    public function show(Request $request, Property $property, Reservation $reservation, ReservationActivityLog $activityLog): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'units', function (array $units, Collection $unitModels) use ($request, $reservation, $activityLog): array {
            $unitId = $request->integer('unit');
            $selected = $unitModels->firstWhere('id', $unitId) ?? $unitModels->first();

            if ($selected === null) {
                return ['unit' => null];
            }

            $selected->load(['ratePlan', 'childAges', 'guests.guest', 'nights' => fn ($nights) => $nights->orderBy('date'), 'nights.room', 'nights.bed', 'nights.ratePlan']);
            $override = $activityLog->priceOverride($reservation, $selected->id);

            return ['unit' => ReservationUnitData::fromModel($selected, $request->user()->can(PermissionEnum::GuestsView->value), $override['reason'] ?? null, $override['by'] ?? null)];
        });
    }

    /**
     * Reservation page, Guests tab.
     */
    public function guests(Request $request, Property $property, Reservation $reservation, ReservationGuestRows $guestRows): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'guests', fn (array $units): array => [
            'guests' => $guestRows->rows($reservation, $units, $request->user()->can(PermissionEnum::GuestsView->value)),
        ]);
    }

    /**
     * Reservation page, Add-ons tab. Rows are ordered by unit, then service date.
     */
    public function addOns(Request $request, Property $property, Reservation $reservation): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'add-ons', function (array $units): array {
            $labels = collect($units)->mapWithKeys(fn (ReservationUnitOptionData $unit): array => [$unit->id => $unit->label]);

            return [
                'addOns' => UnitAddOn::query()
                    ->whereIn('reservation_unit_id', $labels->keys()->all())
                    ->with('chargeItem')
                    ->orderBy('reservation_unit_id')
                    ->orderBy('service_date')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (UnitAddOn $addOn): ReservationAddOnData => ReservationAddOnData::fromModel($addOn, $labels[$addOn->reservation_unit_id]))
                    ->all(),
            ];
        });
    }

    /**
     * Reservation page, Folio tab. `?folio=` picks the folio (an id, or `u{unit id}` for a unit with no folio yet).
     */
    public function folio(Request $request, Property $property, Reservation $reservation, FolioStatement $statement): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'folio', fn (array $units): array => $statement->build($property, $reservation, $units, $request->string('folio')->toString(), [
            'postCharge' => $request->user()->can(PermissionEnum::FolioPostCharge->value),
            'reverse' => $request->user()->can(PermissionEnum::FolioReverse->value),
            'transfer' => $request->user()->can(PermissionEnum::FolioTransfer->value),
            'discount' => $request->user()->can(PermissionEnum::FolioDiscount->value),
            'close' => $request->user()->can(PermissionEnum::FolioClose->value),
            'reopen' => $request->user()->can(PermissionEnum::FolioReopen->value),
        ]));
    }

    /**
     * Reservation page, Activity tab: the log, newest first, 25 to a page.
     */
    public function activity(Request $request, Property $property, Reservation $reservation, ReservationActivityLog $activityLog): Response
    {
        return $this->renderTab($request->user(), $property, $reservation, 'activity', fn (): array => [
            'activity' => ReservationActivityData::collect($activityLog->paginate($reservation), PaginatedDataCollection::class),
        ]);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Every tab gets the header, the summary and the unit
     * list. Only the open tab's own data is read and sent. The form option lists are optional props, read when an
     * overlay opens.
     *
     * @param  'units'|'guests'|'add-ons'|'folio'|'activity'  $tab
     * @param  callable(list<ReservationUnitOptionData>, Collection<int, ReservationUnit>): array<string, mixed>  $tabProps
     */
    private function renderTab(User $user, Property $property, Reservation $reservation, string $tab, callable $tabProps): Response
    {
        $canViewGuest = $user->can(PermissionEnum::GuestsView->value);
        $reservation->load(['booker', 'creator']);
        $overview = app(ReservationOverview::class)->build($reservation, $canViewGuest);
        $options = app(ReservationFormOptions::class);

        return Inertia::render('reservations/show', [
            'reservation' => $overview['reservation'],
            'units' => $overview['units'],
            'tab' => $tab,
            'autoAssignRooms' => (bool) $property->settings?->auto_assign_rooms,
            'currency' => $property->settings?->currency,
            'canViewGuest' => $canViewGuest,
            'canManage' => $user->can(PermissionEnum::ReservationsManage->value),
            'canOverrideRestrictions' => $user->can(PermissionEnum::RatesOverrideRestrictions->value),
            'billingModes' => BillingMode::options(),
            'addUnitOptions' => Inertia::optional(fn () => $options->addUnit($property)),
            'chargeItems' => Inertia::optional(fn () => $options->chargeItems($property)),
            'chargeCodes' => Inertia::optional(fn () => $options->chargeCodes($property)),
            'folioDiscounts' => Inertia::optional(fn () => $options->folioDiscounts($property, $user->can(PermissionEnum::FolioDiscountOpen->value))),
            ...$tabProps($overview['units'], $overview['models']),
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
