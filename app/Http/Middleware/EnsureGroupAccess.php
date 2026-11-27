<?php

namespace App\Http\Middleware;

use App\Models\PropertyGroup;
use App\Models\User;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a staff user into group mode (`/g/{propertyGroup}`) only when they may open two or more Live properties of the
 * group; anyone else gets a 404. In a support session (view as staff) the support user must be allowed to view every
 * one of those properties as staff; group pages only read. The properties go on the request as `groupProperties`, so
 * the page and the sidebar read them once. The group is remembered (`users.last_property_group_id`), so sign-in lands
 * back in group mode. There is no permission team: each property's figures are checked against
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
        abort_unless($group instanceof PropertyGroup, 404);

        $properties = $this->groupProperties->propertiesFor($user, $group);
        abort_if($properties->count() < 2, 404);
        abort_if($this->impersonation->isActive() && ! $properties->every(fn ($property): bool => Gate::forUser($user)->allows('viewAs', $property)), 404);

        $request->attributes->set(self::PROPERTIES, $properties);

        if (! $this->impersonation->isActive() && $request->header('Purpose') !== 'prefetch' && $user->last_property_group_id !== $group->id) {
            User::query()->whereKey($user->id)->toBase()->update(['last_property_group_id' => $group->id]);
            $user->forceFill(['last_property_group_id' => $group->id]);
        }
        setPermissionsTeamId(null);

        return $next($request);
    }
}
