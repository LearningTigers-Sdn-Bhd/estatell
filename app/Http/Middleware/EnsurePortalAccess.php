<?php

namespace App\Http\Middleware;

use App\Enums\CompanyUserStatus;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\Property;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a company user into `/portal/{property:code}` when they have an Active link at that property and the property
 * is Live. Everyone else gets a 404, so the pages stay hidden. A Suspended company still gets in (it can read, not
 * book). Sets the `property` URL default and keeps the link as the request attribute `portalLink`, which the shared
 * props read. See docs/23-company-portal/01-company-portal.md#rules.
 */
class EnsurePortalAccess
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

        abort_unless($property instanceof Property && $user->is_company_user && $user->deactivated_at === null, 404);

        $link = CompanyUser::query()
            ->where('user_id', $user->id)
            ->where('property_id', $property->id)
            ->where('status', CompanyUserStatus::Active->value)
            ->with('company', 'property')
            ->first();

        abort_unless($link instanceof CompanyUser && $property->status === PropertyStatus::Live, 404);

        $request->attributes->set('portalLink', $link);
        URL::defaults(['property' => $property->code]);

        return $next($request);
    }
}
