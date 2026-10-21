<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Channels\RecordChannelAlert;
use App\Enums\ChannelAlertEvent;
use App\Features\ChannelManager;
use App\Http\Controllers\Controller;
use App\Jobs\PullChannelRevisionsJob;
use App\Models\ChannelProperty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

class ChannexWebhookController extends Controller
{
    /**
     * Take a Channex event. A `booking` event starts a pull of the feed, which is the one way revisions are read and
     * acknowledged. The alert events are kept for the admin Channels tab. Events for an unknown property, a property
     * with the channel manager off, or an event kind we do not use are answered 200 and dropped, so Channex does not
     * retry them.
     */
    public function __invoke(Request $request, RecordChannelAlert $recordChannelAlert): JsonResponse
    {
        $event = (string) $request->input('event');
        $payload = $request->input('payload');
        $externalPropertyId = $request->input('property_id');

        $channelProperty = is_string($externalPropertyId)
            ? ChannelProperty::query()->with('property')->where('external_property_id', $externalPropertyId)->first()
            : null;

        if ($channelProperty === null || ! Feature::for($channelProperty->property)->active(ChannelManager::class)) {
            return response()->json(['ok' => true]);
        }

        if ($event === 'booking') {
            PullChannelRevisionsJob::dispatch($channelProperty->id);
        } elseif (($alert = ChannelAlertEvent::tryFrom($event)) !== null) {
            $recordChannelAlert($channelProperty, $alert, is_array($payload) ? $payload : []);
        }

        return response()->json(['ok' => true]);
    }
}
