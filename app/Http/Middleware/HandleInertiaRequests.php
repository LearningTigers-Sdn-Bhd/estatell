<?php

namespace App\Http\Middleware;

use App\Data\Navigation\StaffNavigationData;
use App\Data\Property\PropertySummaryData;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Navigation\AdminNavigation;
use App\Navigation\StaffNavigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'adminNeedsTwoFactor' => RequireAdminTwoFactor::isMissingFor($request->user()),
            'property' => fn () => $this->currentProperty($request),
            'adminNavigation' => fn () => $request->user()?->is_admin ? app(AdminNavigation::class)->items() : null,
            'navigation' => fn () => $this->staffNavigation($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The staff sidebar for the property in the URL, or for the staff user's own property on pages without one.
     * Null for admins and for suspended properties.
     */
    private function staffNavigation(Request $request): ?StaffNavigationData
    {
        $user = $request->user();

        if ($user === null || $user->is_admin) {
            return null;
        }

        $property = $request->route('property');

        if (! $property instanceof Property) {
            $property = $user->property;
        }

        if (! $property instanceof Property || $property->status === PropertyStatus::Suspended) {
            return null;
        }

        // Pages outside /p (account pages) have no permission team yet; staff belong to one property, so use it.
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId($property->id);
        }

        return app(StaffNavigation::class)->build($property, $user, $request->route()?->getName());
    }

    /**
     * The property in the URL, or the signed-in staff user's own property on pages without one.
     */
    private function currentProperty(Request $request): ?PropertySummaryData
    {
        $property = $request->route('property');

        if (! $property instanceof Property) {
            $property = $request->user()?->property;
        }

        return $property instanceof Property ? PropertySummaryData::fromModel($property) : null;
    }
}
