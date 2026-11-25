<?php

namespace App\Http\Middleware;

use App\Features\AiAssistant;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to AI assistant routes while the property's AI assistant feature is off. Pennant's EnsureFeaturesAreActive is not
 * used here because its inactive handler is global and belongs to the Channels upsell.
 */
class EnsureAiAssistantIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        abort_unless($property instanceof Property && Feature::for($property)->active(AiAssistant::class), 404);

        return $next($request);
    }
}
