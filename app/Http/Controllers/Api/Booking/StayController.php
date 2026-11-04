<?php

namespace App\Http\Controllers\Api\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CalendarRequest;
use App\Http\Requests\Booking\StayRequest;
use App\Http\Resources\Booking\V1\BookingCalendarDayResource;
use App\Http\Resources\Booking\V1\BookingOfferResource;
use App\Http\Resources\Booking\V1\BookingSearchResultResource;
use App\Http\Responses\ApiError;
use App\Models\Property;
use App\Services\BookingEngine\BookingCalendar;
use App\Services\BookingEngine\BookingOffers;
use App\Services\BookingEngine\BookingSearch;
use App\Services\BookingEngine\BookingWindow;
use Illuminate\Http\JsonResponse;

class StayController extends Controller
{
    /**
     * @return array<int, mixed>
     */
    public function calendar(CalendarRequest $request, BookingCalendar $calendar): array
    {
        return BookingCalendarDayResource::collection($calendar->forRange($request->attributes->get('booking_property'), $request->from(), $request->to()))->resolve($request);
    }

    /**
     * @return array<int, mixed>|JsonResponse
     */
    public function offers(StayRequest $request, BookingOffers $offers, BookingWindow $window): array|JsonResponse
    {
        /** @var Property $property */
        $property = $request->attributes->get('booking_property');

        if (! $window->contains($property, $request->arrival())) {
            return ApiError::response('outside_window', __('Choose an arrival from today to :months months ahead.', ['months' => BookingWindow::MONTHS_AHEAD]), 422);
        }

        return BookingOfferResource::collection($offers->forStay(
            $property,
            $request->arrival(),
            $request->departure(),
            $request->integer('adults'),
            $request->childAges(),
            $request->filled('nationality') ? $request->string('nationality')->toString() : null,
        ))->resolve($request);
    }

    /**
     * @return array<int, mixed>
     */
    public function search(StayRequest $request, BookingSearch $search): array
    {
        return BookingSearchResultResource::collection($search->lowestPrices(
            $request->attributes->get('booking_client'),
            $request->arrival(),
            $request->departure(),
            $request->integer('adults'),
            $request->childAges(),
        ))->resolve($request);
    }
}
