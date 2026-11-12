<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Webhooks\RetryWebhookDelivery;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WebhookDeliveryController extends Controller
{
    /**
     * Queue one more try of a refused or failed delivery.
     */
    public function retry(Request $request, WebhookDelivery $webhookDelivery, RetryWebhookDelivery $retry): RedirectResponse
    {
        if (! $retry($request->user(), $webhookDelivery)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This delivery cannot be retried now. Turn the endpoint on first, or reload the page.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Retry queued.')]);

        return back();
    }
}
