<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use App\Services\Staff\AssignableRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the onboarding routes (`onboarding.only`). A Live property goes to its dashboard. While a property is
 * Onboarding, only its owner and a support user viewing it as staff may set it up; other staff see the waiting page.
 * PendingApproval is not restricted here, so staff can still reach go-live.
 */
class EnsureOnboarding
{
    public function __construct(
        private Impersonation $impersonation,
        private AssignableRoles $assignableRoles,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $request->route('property');

        if (! $property instanceof Property) {
            return $next($request);
        }

        if ($property->status === PropertyStatus::Live) {
            return to_route('dashboard', ['property' => $property->code]);
        }

        if ($property->status !== PropertyStatus::Onboarding || $request->routeIs('onboarding.waiting')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $this->mayRunOnboarding($user, $property)) {
            return $next($request);
        }

        return to_route('onboarding.waiting', ['property' => $property->code]);
    }

    private function mayRunOnboarding(User $user, Property $property): bool
    {
        if ($this->impersonation->forProperty($property)) {
            return true;
        }

        return $user->property_id === $property->id && $this->assignableRoles->isOwner($user);
    }
}
