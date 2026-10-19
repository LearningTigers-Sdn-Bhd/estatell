<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\CreatePortalReservation;
use App\Data\Portal\PortalRatePlanData;
use App\Data\Portal\PortalRoomTypeData;
use App\Data\Property\CurrencyData;
use App\Enums\CompanyStatus;
use App\Enums\IdentityDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalOffersRequest;
use App\Http\Requests\Portal\StorePortalBookingRequest;
use App\Models\ChargeItem;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\RatePlan;
use App\Services\Portal\PortalBookingMessages;
use App\Services\Portal\PortalBookingQuote;
use App\Services\Portal\PortalBookingRules;
use App\Services\Portal\PortalRatePlans;
use App\Services\Reservations\AutoAddOns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Book a stay: the new booking page, the offers for a search and the new booking. The company always comes from the
 * `portalLink` the middleware set, never from the request.
 */
class PortalBookController extends Controller
{
    /**
     * The new booking page. A Suspended company gets the page without the form.
     */
    public function create(Request $request, Property $property, PortalRatePlans $portalRatePlans, PortalBookingRules $rules, AutoAddOns $autoAddOns): Response
    {
        $link = $this->link($request);
        $property->loadMissing('settings');
        $suspended = $link->company->status === CompanyStatus::Suspended;

        return Inertia::render('portal/bookings/create', [
            'propertyName' => $property->name,
            'propertyCode' => $property->code,
            'companyName' => $link->company->name,
            'companySuspended' => $suspended,
            'today' => $rules->businessDate($property)->toDateString(),
            'cancelCutoffDays' => $link->company->portal_cancel_cutoff_days,
            'ratePlans' => $suspended ? [] : $portalRatePlans->bookablePlansFor($link->company)->get()
                ->map(fn (RatePlan $ratePlan): PortalRatePlanData => PortalRatePlanData::fromModel($ratePlan))->all(),
            'roomTypes' => $suspended ? [] : $portalRatePlans->roomTypesFor($link->company)->get()
                ->map(fn ($roomType): PortalRoomTypeData => PortalRoomTypeData::fromModel($roomType))->all(),
            'idTypes' => IdentityDocumentType::options(),
            'autoAddOns' => array_map(fn (ChargeItem $item): string => $item->name, $autoAddOns->items($property)),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    /**
     * The offers as JSON: for each line, the room types of its plan with their price, what is left, or the reason they
     * are blocked.
     */
    public function offers(PortalOffersRequest $request, Property $property, PortalBookingQuote $bookingQuote): JsonResponse
    {
        $link = $this->link($request);

        abort_if($link->company->status === CompanyStatus::Suspended, 403);

        return response()->json(['lines' => $bookingQuote->offers($property, $link->company, $request->arrival(), $request->departure(), $request->lines())]);
    }

    /**
     * Create the booking. A refusal goes back to the page as errors keyed by line, and nothing is saved.
     */
    public function store(StorePortalBookingRequest $request, Property $property, CreatePortalReservation $createPortalReservation, PortalBookingMessages $messages): RedirectResponse
    {
        $result = $createPortalReservation($request->user(), $this->link($request), $request->toBookingData());

        if ($result->isRefused()) {
            return back(fallback: route('portal.bookings.create', ['property' => $property]))->withErrors($messages->errors($result));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Booking :reference confirmed.', ['reference' => $result->reservation->reference])]);

        return to_route('portal.bookings.show', ['property' => $property, 'reservation' => $result->reservation->id]);
    }

    private function link(Request $request): CompanyUser
    {
        $link = $request->attributes->get('portalLink');

        abort_unless($link instanceof CompanyUser, 404);

        return $link;
    }
}
