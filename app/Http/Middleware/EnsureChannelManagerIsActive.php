<?php

namespace App\Http\Middleware;

use App\Features\ChannelManager;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to Channels routes while the property's Channel manager feature is off. There is no upsell page.
 */
class EnsureChannelManagerIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(ChannelManager::class), 404);

        return $next($request);
    }
}
