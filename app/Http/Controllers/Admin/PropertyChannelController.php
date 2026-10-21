<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Channels\QueueChannelFullSync;
use App\Actions\Channels\RemoveChannelRatePlan;
use App\Actions\Channels\RemoveChannelRoomType;
use App\Actions\Channels\ResolveChannelAlert;
use App\Actions\Channels\RetryChannelRevision;
use App\Actions\Channels\StartChannelStructureSync;
use App\Http\Controllers\Controller;
use App\Models\ChannelAlert;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Property;
use App\Services\Channels\ChannelActionResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PropertyChannelController extends Controller
{
    /**
     * Queue the sync of the property's room types and rate plans to Channex. It runs in the background and the tab
     * shows its progress, so a started sync gives no toast: the progress toast is the feedback. A refusal does.
     */
    public function sync(Request $request, Property $property, StartChannelStructureSync $startChannelStructureSync): RedirectResponse
    {
        $result = $startChannelStructureSync($request->user(), $property);

        return $result->ok ? back(fallback: route('admin.properties.channels', $property)) : $this->respond($property, $result);
    }

    /**
     * Send the next 500 days of availability, rates and restrictions again.
     */
    public function fullRefresh(Request $request, Property $property, QueueChannelFullSync $queueChannelFullSync): RedirectResponse
    {
        $channelProperty = $property->channelProperty()->with('property')->first();

        return $this->respond($property, $channelProperty === null
            ? ChannelActionResult::failed(__('Sync the structure to Channex first.'))
            : $queueChannelFullSync($channelProperty, $request->user()));
    }

    /**
     * Remove one rate plan from Channex.
     */
    public function destroyRatePlan(Request $request, Property $property, ChannelRatePlanMapping $ratePlanMapping, RemoveChannelRatePlan $removeChannelRatePlan): RedirectResponse
    {
        abort_unless($ratePlanMapping->channelProperty->property_id === $property->id, 404);

        return $this->respond($property, $removeChannelRatePlan($request->user(), $ratePlanMapping));
    }

    /**
     * Remove one room type from Channex.
     */
    public function destroyRoomType(Request $request, Property $property, ChannelRoomTypeMapping $roomTypeMapping, RemoveChannelRoomType $removeChannelRoomType): RedirectResponse
    {
        abort_unless($roomTypeMapping->channelProperty->property_id === $property->id, 404);

        return $this->respond($property, $removeChannelRoomType($request->user(), $roomTypeMapping));
    }

    /**
     * Try again to save a booking that was refused.
     */
    public function retryRevision(Request $request, Property $property, ChannelBookingRevision $revision, RetryChannelRevision $retryChannelRevision): RedirectResponse
    {
        abort_unless($revision->channelProperty->property_id === $property->id, 404);

        return $this->respond($property, $retryChannelRevision($request->user(), $revision));
    }

    /**
     * Close an alert.
     */
    public function resolveAlert(Request $request, Property $property, ChannelAlert $alert, ResolveChannelAlert $resolveChannelAlert): RedirectResponse
    {
        abort_unless($alert->channelProperty->property_id === $property->id, 404);

        return $this->respond($property, $resolveChannelAlert($request->user(), $alert));
    }

    private function respond(Property $property, ChannelActionResult $result): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return back(fallback: route('admin.properties.channels', $property));
    }
}
