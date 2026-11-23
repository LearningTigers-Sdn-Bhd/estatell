<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Pos\ConnectLoyverse;
use App\Features\PosIntegration;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Pos\PosRefusalMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Laravel\Pennant\Feature;

class LoyverseOAuthCallbackController extends Controller
{
    /**
     * Loyverse sends the merchant back here with `code` and the `state` Estatell made in `LoyverseIntegrationController`.
     * The state is single use and lives 10 minutes; it names the property and the staff member who started the connect, and
     * the signed-in user must be that staff member.
     */
    public function __invoke(Request $request, ConnectLoyverse $connectLoyverse): RedirectResponse
    {
        $state = Cache::pull('loyverse-oauth:'.(string) $request->query('state'));
        $property = is_array($state) ? Property::query()->find((int) $state['property_id']) : null;

        // Only the staff member who started the connect can finish it, so a leaked link or state is useless to anyone else.
        if ($property === null || $request->user()?->id !== (int) $state['user_id']) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That Loyverse link expired. Start again from POS integrations.')]);

            return redirect()->route('home.redirect');
        }

        if (! Feature::for($property)->active(PosIntegration::class)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('POS integration is not active for this property.')]);

            return to_route('settings.pos-integrations.index', $property);
        }

        $refusal = $request->filled('code') ? $connectLoyverse($request->user(), $property, (string) $request->query('code')) : ConnectLoyverse::REFUSAL_CONNECT_FAILED;

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Loyverse connected.')]
            : ['type' => 'error', 'message' => PosRefusalMessages::message($refusal)]);

        return to_route('settings.pos-integrations.index', $property);
    }
}
