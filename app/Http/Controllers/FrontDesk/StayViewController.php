<?php

namespace App\Http\Controllers\FrontDesk;

use App\Data\Property\CurrencyData;
use App\Data\StayView\StayFolioData;
use App\Enums\HousekeepingStatus;
use App\Enums\PermissionEnum;
use App\Enums\StayViewGrouping;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\StayViewTimelineRequest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Property\PropertyToday;
use App\Services\StayView\StayFolioPeek;
use App\Services\StayView\StayViewQuery;
use Inertia\Inertia;
use Inertia\Response;

class StayViewController extends Controller
{
    /**
     * The Timeline View: a window of nights, 14 days from today by default and 30 at most. Rooms, rates and filter
     * options are bounded lists the page filters in the browser. Only a change of window asks the server again.
     */
    public function timeline(StayViewTimelineRequest $request, Property $property, PropertyToday $propertyToday, StayViewQuery $query): Response
    {
        [$from, $to] = $request->window($propertyToday->date($property));
        $window = $query->window($property, $from, $to);

        return Inertia::render('front-desk/timeline', [
            'window' => $window,
            'rooms' => $query->rows($property, $from),
            'stays' => $query->stays($property, $from, $to),
            'dayUses' => $query->dayUses($property, $from, $to),
            'blocks' => $query->blocks($property, $from, $to),
            'rates' => $query->rates($property, $from, $to),
            'filters' => $request->filters(),
            'groupingOptions' => StayViewGrouping::options(),
            'statusOptions' => HousekeepingStatus::options(),
            'weekendDays' => $property->settings->weekend_nights->isoWeekdays(),
            'currency' => CurrencyData::forProperty($property),
            'canViewReservations' => $request->user()->can(PermissionEnum::ReservationsView->value),
            'canManageReservations' => $request->user()->can(PermissionEnum::ReservationsManage->value),
            'canOverrideRestrictions' => $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value),
            'canWaiveFees' => $request->user()->can(PermissionEnum::FrontDeskWaiveFees->value),
        ]);
    }

    /**
     * The newest folio lines and the balance of a reservation, loaded when the peek sheet opens. The route needs
     * `reservations.view`, because the lines are folio data.
     */
    public function folio(Property $property, Reservation $reservation, StayFolioPeek $peek): StayFolioData
    {
        return $peek->for($reservation);
    }
}
