<?php

namespace App\Http\Controllers\Reservations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\FreeUnitsRequest;
use App\Http\Requests\Reservations\QuoteBookingLineRequest;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Availability\FreeUnits;
use App\Services\Reservations\BookingQuote;
use Illuminate\Http\JsonResponse;

class ReservationBookingController extends Controller
{
    /**
     * Price one booking line for the booking form. A line that cannot be priced answers 200 with `errors`.
     */
    public function quote(QuoteBookingLineRequest $request, Property $property, BookingQuote $bookingQuote): JsonResponse
    {
        return response()->json($bookingQuote->line(
            $property,
            $request->integer('room_type_id'),
            $request->integer('rate_plan_id'),
            $request->date('arrival_date', 'Y-m-d')->toImmutable(),
            $request->date('departure_date', 'Y-m-d')->toImmutable(),
            $request->integer('adults'),
            array_map(intval(...), $request->validated('child_ages') ?? []),
            $request->integer('beds', 1),
        ));
    }

    /**
     * The rooms or beds of a room type that are free on every night of a stay. An unknown room type has none.
     */
    public function freeUnits(FreeUnitsRequest $request, Property $property, FreeUnits $freeUnits): JsonResponse
    {
        $roomType = RoomType::query()->where('property_id', $property->id)->active()->find($request->integer('room_type_id'));

        return response()->json(['units' => $roomType === null ? [] : $freeUnits->forStay(
            $roomType,
            $request->date('arrival_date', 'Y-m-d'),
            $request->date('departure_date', 'Y-m-d'),
        )]);
    }
}
