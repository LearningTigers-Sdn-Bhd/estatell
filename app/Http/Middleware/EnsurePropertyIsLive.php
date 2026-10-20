<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps operational modules closed until the property is Live. Runs after `property.access`, which
 * already sends suspended properties to their own page. A property still in setup goes to the step the wizard is on;
 * a property awaiting approval goes to go-live.
 */
class EnsurePropertyIsLive
{
    public function __construct(private OnboardingProgress $progress) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        if (! $property instanceof Property || $property->status === PropertyStatus::Live) {
            return $next($request);
        }

        if ($property->status === PropertyStatus::PendingApproval) {
            return to_route('onboarding.go-live.show', ['property' => $property->code]);
        }

        return to_route($this->currentStepRoute($property), ['property' => $property->code]);
    }

    /**
     * The page of the wizard's current step.
     */
    private function currentStepRoute(Property $property): string
    {
        return $this->progress->currentStep($property)->routeName();
    }
}
