<?php

namespace App\Http\Middleware;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets staff into their own property's `/p/{property:code}` routes and sets the property context.
 * Impersonation and the admin view-as redirect come with docs/01-platform/04-impersonation.md.
 */
class EnsurePropertyAccess
{
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

        abort_unless($property instanceof Property && $user->property_id === $property->id, 404);

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
