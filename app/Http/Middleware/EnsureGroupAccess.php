<?php

namespace App\Http\Middleware;

use App\Models\PropertyGroup;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a staff user into group mode (`/g/{propertyGroup}`) only when they may open two or more Live properties of the
 * group; anyone else, and any support session, gets a 404. The properties go on the request as `groupProperties`, so
 * the page and the sidebar read them once. There is no permission team: each property's figures are checked against
 * the role there. See docs/03-staff/05-group-mode.md.
 */
class EnsureGroupAccess
{
    public const PROPERTIES = 'groupProperties';

    public function __construct(private GroupProperties $groupProperties, private Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException(redirectTo: route('login'));
        }

        $group = $request->route('propertyGroup');
        abort_unless($group instanceof PropertyGroup && ! $this->impersonation->isActive(), 404);

        $properties = $this->groupProperties->propertiesFor($user, $group);
        abort_if($properties->count() < 2, 404);

        $request->attributes->set(self::PROPERTIES, $properties);
        setPermissionsTeamId(null);

        return $next($request);
    }
}
