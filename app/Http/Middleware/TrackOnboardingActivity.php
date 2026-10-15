<?php

namespace App\Http\Middleware;

use App\Actions\Onboarding\RecordOnboardingActivity;
use App\Models\Property;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records onboarding activity for a successful write (any method except GET, HEAD and OPTIONS). Runs after
 * EnsureOnboarding, so the property is already known to be in its setup.
 */
class TrackOnboardingActivity
{
    public function __construct(private RecordOnboardingActivity $recordActivity) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $property = $request->route('property');

        if ($request->isMethodSafe() || $response->getStatusCode() >= 400 || ! $property instanceof Property) {
            return $response;
        }

        ($this->recordActivity)($property);

        return $response;
    }
}
