<?php

namespace App\Http\Controllers\FrontDesk;

use App\Data\Property\CurrencyData;
use App\Data\Shared\BadgeData;
use App\Data\StayView\StayFolioData;
use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use App\Enums\RoomBlockReason;
use App\Enums\RoomDayState;
use App\Enums\StayViewGrouping;
use App\Features\Facilities;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\StayViewRoomsRequest;
use App\Http\Requests\FrontDesk\StayViewTimelineRequest;
use App\Http\Requests\Rooms\RoomBlockRequest;
use App\Models\Facility;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Facilities\FacilityTimeline;
use App\Services\Housekeeping\HousekeepingDate;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\StayView\StayFolioPeek;
use App\Services\StayView\StayViewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class StayViewController extends Controller
{
    /**
     * The Timeline View: a window of nights, 14 days from today by default and 30 at most. Rooms, rates and filter
     * options are bounded lists the page filters in the browser. Only a change of window asks the server again. With
     * the Facilities feature on, the facility rows and their day cells come too; otherwise `facilities` is null.
     */
    public function timeline(StayViewTimelineRequest $request, Property $property, PropertyToday $propertyToday, StayViewQuery $query, FacilityTimeline $facilities): Response
    {
        [$from, $to] = $request->window($propertyToday->date($property));
        $window = $query->window($property, $from, $to);
        $hasFacilities = Feature::for($property)->active(Facilities::class);

        return Inertia::render('front-desk/timeline', [
            'window' => $window,
            'rooms' => $query->rows($property, $from),
            'stays' => $query->stays($property, $from, $to),
            'dayUses' => $query->dayUses($property, $from, $to),
            'blocks' => $query->blocks($property, $from, $to),
            'rates' => $query->rates($property, $from, $to),
            'facilities' => $hasFacilities ? $facilities->rows($property, $from, $to) : null,
            'facilityCells' => $hasFacilities ? $facilities->cells($property, $from, $to) : [],
            'filters' => $request->filters(),
            'groupingOptions' => StayViewGrouping::options(),
            'statusOptions' => HousekeepingStatus::options(),
            'weekendDays' => $property->settings->weekend_nights->isoWeekdays(),
            'currency' => CurrencyData::forProperty($property),
            'canViewReservations' => $request->user()->can(PermissionEnum::ReservationsView->value),
            'canViewEvents' => $hasFacilities && $request->user()->can(PermissionEnum::EventsView->value),
            'canBookFacility' => $hasFacilities && $request->user()->can(PermissionEnum::FacilitiesBook->value),
            'canManageEvents' => $hasFacilities && $request->user()->can(PermissionEnum::EventsManage->value),
            'canManageReservations' => $request->user()->can(PermissionEnum::ReservationsManage->value),
            'canOverrideRestrictions' => $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value),
            'canWaiveFees' => $request->user()->can(PermissionEnum::FrontDeskWaiveFees->value),
            'canBlockRooms' => $request->user()->can(PermissionEnum::RoomsBlock->value),
            'blockReasons' => RoomBlockReason::options(),
            'maxBlockNights' => RoomBlockRequest::MAX_NIGHTS,
        ]);
    }

    /**
     * The Room View: one card for each room or bed on one date, the business date by default. It reads the Timeline's
     * data for that date and leaves out the rates. The stays start a night earlier, because a guest who leaves on the
     * date holds no night on it, and the card still shows the departure. The cards and the day summary are derived in
     * the browser.
     */
    public function rooms(StayViewRoomsRequest $request, Property $property, PropertyToday $propertyToday, CurrentBusinessDate $businessDate, HousekeepingDate $housekeepingDate, StayViewQuery $query): Response
    {
        $date = $request->onDate($businessDate->date($property) ?? $propertyToday->date($property));

        return Inertia::render('front-desk/rooms', [
            'window' => $query->window($property, $date, $date),
            'rooms' => $query->rows($property, $date),
            'stays' => $query->stays($property, $date->subDay(), $date),
            'blocks' => $query->blocks($property, $date, $date),
            'filters' => $request->filters(),
            'groupingOptions' => StayViewGrouping::options(),
            'statusOptions' => HousekeepingStatus::options(),
            'showOptions' => RoomDayState::options(),
            'stateBadges' => array_map(BadgeData::fromRoomDayState(...), RoomDayState::cases()),
            'currency' => CurrencyData::forProperty($property),
            'canViewReservations' => $request->user()->can(PermissionEnum::ReservationsView->value),
            'canManageReservations' => $request->user()->can(PermissionEnum::ReservationsManage->value),
            'canBlockRooms' => $request->user()->can(PermissionEnum::RoomsBlock->value),
            'blockReasons' => RoomBlockReason::options(),
            'maxBlockNights' => RoomBlockRequest::MAX_NIGHTS,
            'canUpdateHousekeeping' => $request->user()->can(PermissionEnum::HousekeepingUpdate->value),
            'housekeepingDate' => $housekeepingDate->for($property)->toDateString(),
        ]);
    }

    /**
     * The room total, paid amount and balance of a reservation, loaded when the peek sheet opens. The route needs
     * `reservations.view`, because the figures are folio data.
     */
    public function folio(Property $property, Reservation $reservation, StayFolioPeek $peek): StayFolioData
    {
        return $peek->for($property, $reservation);
    }

    /**
     * The live bookings of one facility on one date, loaded when the Timeline's facility day peek opens. The
     * `facilities` middleware returns 404 when the feature is off.
     */
    public function facilityDay(Property $property, Facility $facility, string $date, FacilityTimeline $facilities): JsonResponse
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        abort_unless($day instanceof CarbonImmutable && $day->toDateString() === $date, 404);

        return response()->json($facilities->day($facility, $day));
    }
}
