<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Services\Platform\Impersonation;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the property context for `/p/{property:code}` routes and lets in, in order: staff of that property, a support
 * user viewing it as staff (an open impersonation session), or, for a GET by a user who may view the property as
 * staff (an admin, or its partner), a redirect to the view-as page. A non-GET request from such a user gets a 403,
 * so a write is never replayed. Anyone else gets a 404. See docs/01-platform/04-impersonation.md#middleware.
 */
class EnsurePropertyAccess
{
    public function __construct(private Impersonation $impersonation) {}

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

        if ($user->property_id !== $property->id && ! $this->impersonation->forProperty($property)) {
            abort_unless(Gate::forUser($user)->allows('viewAs', $property), 404);
            abort_unless($request->isMethod('GET'), 403);

            return redirect()->route('view-as.show', ['property' => $property->code, 'to' => $request->getRequestUri()]);
        }

        setPermissionsTeamId($property->id);
        URL::defaults(['property' => $property->code]);

        if ($property->status === PropertyStatus::Suspended && ! $request->routeIs('property.suspended')) {
            return redirect()->route('property.suspended', ['property' => $property->code]);
        }

        if ($property->status !== PropertyStatus::Suspended && $request->routeIs('property.suspended')) {
            return redirect()->route('dashboard', ['property' => $property->code]);
        }

        return $next($request);
    }
}
