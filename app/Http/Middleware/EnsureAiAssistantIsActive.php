<?php

namespace App\Http\Middleware;

use App\Enums\AiScope;
use App\Features\AiAssistant;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\AiAssistant\AiScopes;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 to AI assistant routes while the property's AI assistant feature is off. With a scope (`ai-assistant:concierge`)
 * the scope must be on too. The property is the route's `{property}`, or the stay's property on a Concierge stay route,
 * so this runs after `concierge` there. Pennant's EnsureFeaturesAreActive is not used here because its inactive handler is
 * global and belongs to the Channels upsell.
 */
class EnsureAiAssistantIsActive
{
    public function __construct(private readonly AiScopes $scopes) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $property = $request->route('property');
        $stay = $request->attributes->get('concierge.stay');

        if (! $property instanceof Property && $stay instanceof ReservationUnit) {
            $property = $stay->property;
        }

        abort_unless($property instanceof Property && Feature::for($property)->active(AiAssistant::class), 404);
        abort_unless($scope === null || $this->scopes->isOn($property, AiScope::from($scope)), 404);

        return $next($request);
    }
}
