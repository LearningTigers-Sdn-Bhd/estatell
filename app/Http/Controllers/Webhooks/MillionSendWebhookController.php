<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Notifications\ApplyMailDeliveryEvent;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MillionSendWebhookController extends Controller
{
    /**
     * Take a MillionSend delivery event and move the matching mail log row on. Every event with a valid signature is
     * answered 200, including events we ignore and messages we do not know, so MillionSend does not retry them.
     * To verify: the payload fields, until a real delivery is seen.
     */
    public function __invoke(Request $request, ApplyMailDeliveryEvent $applyMailDeliveryEvent): JsonResponse
    {
        $type = $request->input('type');
        $emailId = $request->input('data.email_id');
        $reason = $request->input('data.bounce.message');

        if (is_string($type) && is_string($emailId)) {
            $applyMailDeliveryEvent($type, $emailId, is_string($reason) ? $reason : null);
        }

        return response()->json(['ok' => true]);
    }
}
