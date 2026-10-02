<?php

namespace App\Http\Controllers\Reservations;

use App\Actions\Reservations\CreateBooking;
use App\Enums\BillingMode;
use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\PermissionEnum;
use App\Enums\ReservationSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\FreeUnitsRequest;
use App\Http\Requests\Reservations\QuoteBookingLineRequest;
use App\Http\Requests\Reservations\StoreBookingRequest;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Availability\FreeUnits;
use App\Services\Guests\CountryOptions;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Reservations\BookingErrorKeys;
use App\Services\Reservations\BookingQuote;
use App\Services\Reservations\ReservationFormOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReservationBookingController extends Controller
{
    /**
     * The booking form for a new booking.
     */
    public function new(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today): Response
    {
        return $this->form($request, $property, $options, $businessDate, $today, walkIn: false);
    }

    /**
     * The same form as a walk-in: the source is fixed, the arrival is the business date and a room or bed is required.
     */
    public function walkIn(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today): Response
    {
        return $this->form($request, $property, $options, $businessDate, $today, walkIn: true);
    }

    /**
     * Save the booking. A refusal goes back to the form as errors keyed by line, and nothing is saved.
     */
    public function store(StoreBookingRequest $request, Property $property, CreateBooking $createBooking, BookingErrorKeys $errorKeys): RedirectResponse
    {
        $result = $createBooking($request->user(), $property, $request->toBookingData());

        if ($result->isRefused()) {
            return back(fallback: route('reservations.new', ['property' => $property]))->withErrors(
                $errorKeys->errors($result, $request->unitsPerLine(), $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value)),
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reservation :reference created.', ['reference' => $result->reservation->reference])]);

        return to_route('reservations.show', ['property' => $property, 'reservation' => $result->reservation]);
    }

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
            $request->addOns(),
            $request->filled('override_total') ? $request->string('override_total')->toString() : null,
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

    private function form(Request $request, Property $property, ReservationFormOptions $options, CurrentBusinessDate $businessDate, PropertyToday $today, bool $walkIn): Response
    {
        $canManageGuests = $request->user()->can(PermissionEnum::GuestsManage->value);
        $sources = $walkIn ? [ReservationSource::WalkIn] : [ReservationSource::Direct, ReservationSource::Phone];

        return Inertia::render('reservations/create', [
            'isWalkIn' => $walkIn,
            'sources' => array_map(fn (ReservationSource $source): array => ['value' => $source->value, 'label' => $source->label()], $sources),
            'today' => ($businessDate->date($property) ?? $today->date($property))->toDateString(),
            'addUnitOptions' => $options->addUnit($property),
            'chargeItems' => $options->chargeItems($property),
            'discounts' => $options->roomDiscounts($property),
            'billingModes' => BillingMode::options(),
            'autoAssignRooms' => (bool) $property->settings?->auto_assign_rooms,
            'currency' => $property->settings?->currency,
            'canOverrideRestrictions' => $request->user()->can(PermissionEnum::RatesOverrideRestrictions->value),
            'canManageGuests' => $canManageGuests,
            'countries' => $canManageGuests ? app(CountryOptions::class)->options() : [],
            'genders' => Gender::options(),
            'locales' => Locale::options(),
        ]);
    }
}
