<?php

namespace App\Http\Middleware;

use App\Data\Navigation\NavItemData;
use App\Data\Navigation\StaffNavigationData;
use App\Data\NightAudit\BusinessDateData;
use App\Data\Onboarding\OnboardingProgressData;
use App\Data\Platform\ImpersonationData;
use App\Data\Property\PropertySummaryData;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Navigation\AccountNavigation;
use App\Navigation\AdminNavigation;
use App\Navigation\PartnerNavigation;
use App\Navigation\PortalNavigation;
use App\Navigation\StaffNavigation;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Platform\Impersonation;
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
            'impersonation' => fn () => $this->impersonation($request),
            'supportTwoFactor' => RequireSupportTwoFactor::sharedPropFor($request->user()),
            'isDemo' => app()->environment('demo'),
            'property' => fn () => $this->currentProperty($request),
            'businessDate' => fn () => $this->businessDate($request),
            'adminNavigation' => fn () => $request->user()?->is_admin ? app(AdminNavigation::class)->items() : null,
            'portalNavigation' => fn () => $request->user()?->is_company_user ? app(PortalNavigation::class)->items($request) : null,
            'portalCompany' => fn () => $this->portalCompany($request),
            'portalSwitcher' => fn () => $request->user()?->is_company_user ? app(PortalNavigation::class)->switcher($request, $request->user()) : null,
            'partnerNavigation' => fn () => $request->user()?->partner_id !== null ? app(PartnerNavigation::class)->items() : null,
            'accountNavigation' => fn () => $request->user() !== null && $request->routeIs('account.*') ? app(AccountNavigation::class)->items($request) : null,
            'navigation' => fn () => $this->staffNavigation($request),
            'commandShortcuts' => fn () => $this->commandShortcuts($request),
            'csrfToken' => fn () => csrf_token(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            ...($this->isOnboardingPage($request) ? ['onboarding' => fn () => $this->onboardingProgress($request)] : []),
        ];
    }

    /**
     * The wizard pages share their progress. The waiting page uses the auth layout and needs none.
     */
    private function isOnboardingPage(Request $request): bool
    {
        return $request->routeIs('onboarding.*') && ! $request->routeIs('onboarding.waiting');
    }

    private function onboardingProgress(Request $request): ?OnboardingProgressData
    {
        $property = $this->resolveProperty($request);

        return $property === null ? null : OnboardingProgressData::forProperty($property, app(OnboardingProgress::class), app(GoLiveRequests::class));
    }

    /**
     * The support banner data while an admin or partner user views a property as its staff, otherwise null.
     */
    private function impersonation(Request $request): ?ImpersonationData
    {
        $impersonation = app(Impersonation::class);
        $supportUser = $impersonation->supportUser();
        $user = $request->user();

        if ($supportUser === null || $user === null || $user->property === null) {
            return null;
        }

        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId($user->property_id);
        }

        return new ImpersonationData(
            supportUserName: $supportUser->name,
            partnerName: $supportUser->partner?->name,
            userName: $user->name,
            userRole: $user->getRoleNames()->first(),
            propertyName: $user->property->name,
            propertyCode: $user->property->code,
            minutesLeft: $impersonation->remainingMinutes(),
            stopUrl: route('impersonation.stop', absolute: false),
            canCorrectMoney: $impersonation->canCorrectMoney(),
        );
    }

    /**
     * The staff sidebar for the property in the URL, or for the staff user's own property on pages without one.
     * Null for admins and for suspended properties.
     */
    private function staffNavigation(Request $request): ?StaffNavigationData
    {
        $user = $request->user();

        if ($user === null || $user->is_admin || $user->partner_id !== null || $user->is_company_user) {
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
        $property = $this->resolveProperty($request);

        return $property === null ? null : PropertySummaryData::fromModel($property);
    }

    /**
     * The current business date of that property. Null until the property goes live, and for admins.
     */
    private function businessDate(Request $request): ?BusinessDateData
    {
        $property = $this->resolveProperty($request);

        if ($property === null || $request->user()?->is_admin || $property->status === PropertyStatus::Suspended) {
            return null;
        }

        $businessDate = app(CurrentBusinessDate::class)->get($property);

        return $businessDate === null ? null : BusinessDateData::fromBusinessDate($businessDate, $property);
    }

    private function resolveProperty(Request $request): ?Property
    {
        if ($request->user()?->is_company_user) {
            return null;
        }

        $property = $request->route('property');

        if (! $property instanceof Property) {
            $property = $request->user()?->property;
        }

        return $property instanceof Property ? $property : null;
    }

    /**
     * The company and property of the open `/portal/{code}` page, from the link `EnsurePortalAccess` found.
     *
     * @return array{companyName: string, propertyName: string, propertyCode: string}|null
     */
    private function portalCompany(Request $request): ?array
    {
        $link = $request->attributes->get('portalLink');

        return $link instanceof CompanyUser
            ? ['companyName' => $link->company->name, 'propertyName' => $link->property->name, 'propertyCode' => $link->property->code]
            : null;
    }

    /** @return list<NavItemData> */
    private function commandShortcuts(Request $request): array
    {
        $user = $request->user();
        $property = $user?->property;

        if ($property === null || $property->status !== PropertyStatus::Live) {
            return [];
        }

        setPermissionsTeamId($property->id);

        return $user->can(PermissionEnum::ReservationsManage->value) ? [
            new NavItemData(__('New booking'), route('reservations.new', ['property' => $property->code], absolute: false), 'calendar-check'),
        ] : [];
    }
}
