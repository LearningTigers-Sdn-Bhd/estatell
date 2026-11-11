<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\SaveGatewayEvent;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCurlecEventJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurlecWebhookController extends Controller
{
    /**
     * Save the event and answer at once, because Curlec waits only 5 seconds and disables a webhook that keeps failing. A
     * job processes it. A repeat of an event that is still unprocessed queues it again, which is safe because one order
     * makes one booking. See docs/29-booking-engine/04-payment.md#webhook.
     */
    public function __invoke(Request $request, SaveGatewayEvent $saveGatewayEvent): JsonResponse
    {
        $eventType = $request->input('event');

        if (is_string($eventType) && $eventType !== '') {
            $event = $saveGatewayEvent($request->header('x-razorpay-event-id'), $eventType, $request->json()->all(), $request->getContent());

            if ($event->processed_at === null) {
                ProcessCurlecEventJob::dispatch($event->id);
            }
        }

        return response()->json(['ok' => true]);
    }
}
