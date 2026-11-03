<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\ApproveNearbyPlace;
use App\Actions\BookingEngine\RejectNearbyPlace;
use App\Data\BookingEngine\AdminNearbyPlaceData;
use App\Enums\NearbyPlaceStatus;
use App\Http\Controllers\Controller;
use App\Models\NearbyPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review of the shared nearby place list. See docs/28-booking-engine/06-content-registries.md#nearby-places.
 */
class NearbyPlaceController extends Controller
{
    /**
     * List every place, Pending first. The browser filters by status and search.
     */
    public function index(): Response
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
        ]);
    }

    public function approve(Request $request, NearbyPlace $nearbyPlace, ApproveNearbyPlace $approveNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $approveNearbyPlace($request->user(), $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name approved.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return to_route('admin.nearby-places.index');
    }

    public function reject(Request $request, NearbyPlace $nearbyPlace, RejectNearbyPlace $rejectNearbyPlace): RedirectResponse
    {
        Inertia::flash('toast', $rejectNearbyPlace($request->user(), $nearbyPlace)
            ? ['type' => 'success', 'message' => __(':name rejected and removed from the properties that had it.', ['name' => $nearbyPlace->name])]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return to_route('admin.nearby-places.index');
    }
}
