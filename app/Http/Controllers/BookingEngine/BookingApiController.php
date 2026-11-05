<?php

namespace App\Http\Controllers\BookingEngine;

use App\Actions\BookingEngine\AddBookingReturnAddress;
use App\Actions\BookingEngine\IssueBookingApiToken;
use App\Actions\BookingEngine\RemoveBookingReturnAddress;
use App\Actions\BookingEngine\RevokeBookingApiToken;
use App\Actions\BookingEngine\SetUpBookingSite;
use App\Data\BookingEngine\BookingWebsiteApiData;
use App\Enums\BookingEngineMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\StoreBookingReturnAddressRequest;
use App\Models\BookingReturnAddress;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The API tab of Settings › Booking website, for an API only property: its token and return addresses. A Microsite
 * property answers 404. See docs/29-booking-engine/09-api-only-mode.md#api-tab.
 */
class BookingApiController extends Controller
{
    public function show(Property $property, SetUpBookingSite $setUpBookingSite): Response
    {
        $this->apiOnlyOrFail($property, $setUpBookingSite);

        return Inertia::render('settings/booking-website/api', [
            'api' => BookingWebsiteApiData::forProperty($property, AddBookingReturnAddress::LIMIT),
        ]);
    }

    /**
     * Create the token, or replace it. The plain token is flashed once, never stored and never in the URL.
     */
    public function issueToken(Request $request, Property $property, SetUpBookingSite $setUpBookingSite, IssueBookingApiToken $issueBookingApiToken): RedirectResponse
    {
        $this->apiOnlyOrFail($property, $setUpBookingSite);

        Inertia::flash('apiToken', $issueBookingApiToken($request->user(), $property));

        return to_route('settings.booking-website.api', $property);
    }

    public function revokeToken(Request $request, Property $property, SetUpBookingSite $setUpBookingSite, RevokeBookingApiToken $revokeBookingApiToken): RedirectResponse
    {
        $this->apiOnlyOrFail($property, $setUpBookingSite);

        Inertia::flash('toast', $revokeBookingApiToken($request->user(), $property)
            ? ['type' => 'success', 'message' => __('API token revoked.')]
            : ['type' => 'error', 'message' => __('There is no token to revoke.')]);

        return to_route('settings.booking-website.api', $property);
    }

    public function storeReturnAddress(StoreBookingReturnAddressRequest $request, Property $property, SetUpBookingSite $setUpBookingSite, AddBookingReturnAddress $addBookingReturnAddress): RedirectResponse
    {
        $this->apiOnlyOrFail($property, $setUpBookingSite);

        Inertia::flash('toast', $addBookingReturnAddress($request->user(), $property, $request->returnUrl())
            ? ['type' => 'success', 'message' => __('Return address added.')]
            : ['type' => 'error', 'message' => __('That address is already added, or the list is full.')]);

        return to_route('settings.booking-website.api', $property);
    }

    public function destroyReturnAddress(Request $request, Property $property, BookingReturnAddress $bookingReturnAddress, SetUpBookingSite $setUpBookingSite, RemoveBookingReturnAddress $removeBookingReturnAddress): RedirectResponse
    {
        $this->apiOnlyOrFail($property, $setUpBookingSite);

        $removeBookingReturnAddress($request->user(), $bookingReturnAddress);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Return address removed.')]);

        return to_route('settings.booking-website.api', $property);
    }

    private function apiOnlyOrFail(Property $property, SetUpBookingSite $setUpBookingSite): void
    {
        abort_unless($setUpBookingSite($property)->mode === BookingEngineMode::ApiOnly, 404);
    }
}
