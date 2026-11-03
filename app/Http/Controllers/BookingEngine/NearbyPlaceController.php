<?php

namespace App\Http\Controllers\BookingEngine;

use App\Actions\BookingEngine\AddNearbyPlaceFromLink;
use App\Actions\BookingEngine\LinkSuggestedNearbyPlace;
use App\Actions\BookingEngine\RemovePropertyNearbyPlace;
use App\Actions\BookingEngine\ReorderPropertyNearbyPlaces;
use App\Actions\BookingEngine\UpdatePropertyNearbyPlace;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingEngine\AddNearbyPlaceRequest;
use App\Http\Requests\BookingEngine\ReorderNearbyPlacesRequest;
use App\Http\Requests\BookingEngine\UpdateNearbyPlaceRequest;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Services\BookingEngine\GoogleMapsLink;
use App\Services\BookingEngine\InvalidMapsLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The nearby places on the Content tab of Settings > Booking website. See
 * docs/28-booking-engine/06-content-registries.md#nearby-places.
 */
class NearbyPlaceController extends Controller
{
    public function store(AddNearbyPlaceRequest $request, Property $property, GoogleMapsLink $googleMapsLink, AddNearbyPlaceFromLink $addNearbyPlaceFromLink): RedirectResponse
    {
        try {
            $mapsPlace = $googleMapsLink->parse($request->string('google_maps_url')->toString());
        } catch (InvalidMapsLink $exception) {
            throw ValidationException::withMessages(['google_maps_url' => $exception->getMessage()]);
        }

        $place = $addNearbyPlaceFromLink($request->user(), $property, $mapsPlace);

        Inertia::flash('toast', $place === null
            ? ['type' => 'error', 'message' => __('This place is already on your list, or it cannot be added.')]
            : ['type' => 'success', 'message' => __(':name added.', ['name' => $place->name])]);

        return to_route('settings.booking-website.content', $property);
    }

    public function storeSuggested(Request $request, Property $property, NearbyPlace $nearbyPlace, LinkSuggestedNearbyPlace $linkSuggestedNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $linkSuggestedNearbyPlace($request->user(), $property, $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name added.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That place is no longer suggested. Reload the page and try again.')]);

        return to_route('settings.booking-website.content', $property);
    }

    public function update(UpdateNearbyPlaceRequest $request, Property $property, NearbyPlace $nearbyPlace, UpdatePropertyNearbyPlace $updatePropertyNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $updatePropertyNearbyPlace($request->user(), $property, $nearbyPlace, $request->description())
            ? ['type' => 'success', 'message' => __('Place saved.')]
            : ['type' => 'error', 'message' => __('That place is no longer on your list. Reload the page and try again.')]);

        return to_route('settings.booking-website.content', $property);
    }

    public function destroy(Request $request, Property $property, NearbyPlace $nearbyPlace, RemovePropertyNearbyPlace $removePropertyNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $removePropertyNearbyPlace($request->user(), $property, $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name removed.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That place is no longer on your list. Reload the page and try again.')]);

        return to_route('settings.booking-website.content', $property);
    }

    public function reorder(ReorderNearbyPlacesRequest $request, Property $property, ReorderPropertyNearbyPlaces $reorderPropertyNearbyPlaces): RedirectResponse
    {
        if (! $reorderPropertyNearbyPlaces($request->user(), $property, $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Your places changed. Reload the page and try again.')]);
        }

        return to_route('settings.booking-website.content', $property);
    }
}
