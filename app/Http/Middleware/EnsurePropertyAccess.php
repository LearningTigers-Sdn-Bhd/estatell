<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use App\Services\Staff\StaffProperties;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the property context for `/p/{property:code}` routes and lets in, in order: staff with an active membership at
 * that property (their home property, or one of its group they were given), a support user viewing it as staff (an
 * open impersonation session), or, for a GET by a user who may view the property as staff (an admin, or its partner),
 * a redirect to the view-as page. Staff who open another property have it remembered as `users.last_property_id`, where
 * sign-in lands next time (clearing the group of group mode); only a page visit counts, not a prefetch or the logo image. A non-GET request from such a user gets a 403,
 * so a write is never replayed. Anyone else gets a 404, also staff deactivated at the property. See
 * docs/01-platform/04-impersonation.md#middleware and docs/03-staff/04-property-groups.md.
 */
class EnsurePropertyAccess
{
    /** The request attribute holding the ids of the properties the staff user may open, for the switcher. */
    public const ACTIVE_PROPERTY_IDS = 'staffPropertyIds';

    public function __construct(private Impersonation $impersonation, private StaffProperties $staffProperties) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException(redirectTo: route('login'));
        }

        $property = $request->route('property');

        abort_unless($property instanceof Property, 404);

        $impersonating = $this->impersonation->forProperty($property);
        // A support session covers its one property, even when the staff user it views can open others: their
        // memberships do not count while it is open, so another property takes the support route below.
        $propertyIds = $this->impersonation->isActive() ? [] : $this->staffProperties->activePropertyIds($user);
        $request->attributes->set(self::ACTIVE_PROPERTY_IDS, $propertyIds);

        if (! $impersonating && ! in_array($property->id, $propertyIds, true)) {
            abort_unless(Gate::forUser($user)->allows('viewAs', $property), 404);
            abort_unless($request->isMethod('GET'), 403);

            return redirect()->route('view-as.show', ['property' => $property->code, 'to' => $request->getRequestUri()]);
        }

        if (! $impersonating && $this->isPageVisit($request) && ($user->last_property_id !== $property->id || $user->last_property_group_id !== null)) {
            User::query()->whereKey($user->id)->toBase()->update(['last_property_id' => $property->id, 'last_property_group_id' => null]);
            $user->forceFill(['last_property_id' => $property->id, 'last_property_group_id' => null]);
        }

        setPermissionsTeamId($property->id);
        // Roles are per property: never reuse roles loaded for another property earlier in the request or session.
        $user->unsetRelation('roles')->unsetRelation('permissions');
        URL::defaults(['property' => $property->code]);

        if ($property->status === PropertyStatus::Suspended && ! $request->routeIs('property.suspended')) {
            return redirect()->route('property.suspended', ['property' => $property->code]);
        }

        if ($property->status !== PropertyStatus::Suspended && $request->routeIs('property.suspended')) {
            return redirect()->route('dashboard', ['property' => $property->code]);
        }

        return $next($request);
    }

    /**
     * A page the user opened: a GET that loads HTML or an Inertia page, not a hover prefetch nor an image (the property
     * logo is served under the property's URL and shows on group pages).
     */
    private function isPageVisit(Request $request): bool
    {
        return $request->isMethod('GET')
            && $request->header('Purpose') !== 'prefetch'
            && ($request->hasHeader('X-Inertia') || str_contains((string) $request->header('Accept'), 'text/html'));
    }
}
