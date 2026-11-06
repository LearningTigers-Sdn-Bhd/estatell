<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\AddApprovedNearbyPlace;
use App\Actions\BookingEngine\ApproveNearbyPlace;
use App\Actions\BookingEngine\RejectNearbyPlace;
use App\Actions\BookingEngine\RenameNearbyPlace;
use App\Data\BookingEngine\AdminNearbyPlaceData;
use App\Enums\NearbyPlaceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RenameNearbyPlaceRequest;
use App\Http\Requests\BookingEngine\AddNearbyPlaceRequest;
use App\Models\NearbyPlace;
use App\Services\BookingEngine\GoogleMapsLink;
use App\Services\BookingEngine\InvalidMapsLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review of the shared nearby place list. See docs/29-booking-engine/06-content-registries.md#nearby-places.
 */
class NearbyPlaceController extends Controller
{
    /**
     * List every place, Pending first. The browser filters by status and search. The open status tab lives in the URL
     * (`?status=pending`, or `all`) so it survives a refresh.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('admin/nearby-places/index', [
            'places' => NearbyPlace::query()
                ->with('createdByProperty:id,name')
                ->withCount('properties')
                ->orderByRaw("case status when 'pending' then 0 when 'approved' then 1 else 2 end")
                ->latest('id')
                ->get()
                ->map(fn (NearbyPlace $place): AdminNearbyPlaceData => AdminNearbyPlaceData::fromModel($place))
                ->values(),
            'statusOptions' => NearbyPlaceStatus::options(),
            'status' => NearbyPlaceStatus::tryFrom($request->string('status')->toString())->value ?? 'all',
        ]);
    }

    public function store(AddNearbyPlaceRequest $request, GoogleMapsLink $googleMapsLink, AddApprovedNearbyPlace $addApprovedNearbyPlace): RedirectResponse
    {
        try {
            $mapsPlace = $googleMapsLink->parse($request->string('google_maps_url')->toString());
        } catch (InvalidMapsLink $exception) {
            throw ValidationException::withMessages(['google_maps_url' => $exception->getMessage()]);
        }

        Inertia::flash('toast', match ($addApprovedNearbyPlace($request->user(), $mapsPlace)) {
            null => ['type' => 'success', 'message' => __(':name added.', ['name' => $mapsPlace->name])],
            NearbyPlaceStatus::Pending => ['type' => 'success', 'message' => __(':name was pending. It is now approved.', ['name' => $mapsPlace->name])],
            NearbyPlaceStatus::Approved => ['type' => 'error', 'message' => __(':name is already in the list.', ['name' => $mapsPlace->name])],
            NearbyPlaceStatus::Rejected => ['type' => 'error', 'message' => __(':name was rejected. Use Approve on its row.', ['name' => $mapsPlace->name])],
        });

        return $this->backToOpenTab();
    }

    public function update(RenameNearbyPlaceRequest $request, NearbyPlace $nearbyPlace, RenameNearbyPlace $renameNearbyPlace): RedirectResponse
    {
        $renameNearbyPlace($request->user(), $nearbyPlace, $request->string('name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Place saved.')]);

        return $this->backToOpenTab();
    }

    public function approve(Request $request, NearbyPlace $nearbyPlace, ApproveNearbyPlace $approveNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $approveNearbyPlace($request->user(), $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name approved.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return $this->backToOpenTab();
    }

    public function reject(Request $request, NearbyPlace $nearbyPlace, RejectNearbyPlace $rejectNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $rejectNearbyPlace($request->user(), $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name rejected and removed from the properties that had it.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return $this->backToOpenTab();
    }

    private function backToOpenTab(): RedirectResponse
    {
        return back(fallback: route('admin.nearby-places.index'));
    }
}
