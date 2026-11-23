<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLoyverseWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyverseWebhookController extends Controller
{
    /**
     * Answer 200 at once, so Loyverse never counts a slow response as a failure (it retries for 48 hours, then disables
     * the webhook). The work runs in `ProcessLoyverseWebhookJob`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->json('type') === 'receipts.update') {
            ProcessLoyverseWebhookJob::dispatch($request->json()->all());
        }

        return response()->json(['received' => true]);
    }
}
