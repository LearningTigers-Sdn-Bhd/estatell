<?php

namespace App\Http\Middleware;

use App\Features\PosIntegration;
use App\Http\Responses\ApiError;
use App\Models\PosApiClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers `403 pos_disabled` to the POS API while the token's property has the POS integration feature off.
 * Put it after `api.token`, which sets the `pos_client` attribute.
 */
class EnsurePosApiFeatureActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('pos_client');

        if (! $client instanceof PosApiClient || ! Feature::for($client->property)->active(PosIntegration::class)) {
            return ApiError::response('pos_disabled', __('POS integration is not enabled for this property.'), 403);
        }

        return $next($request);
    }
}
