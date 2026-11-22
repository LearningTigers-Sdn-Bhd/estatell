<?php

namespace App\Http\Middleware;

use App\Data\Navigation\NavItemData;
use App\Data\Navigation\StaffNavigationData;
use App\Data\NightAudit\BusinessDateData;
use App\Data\Onboarding\OnboardingProgressData;
use App\Data\Platform\ImpersonationData;
use App\Data\Portal\PortalSwitcherData;
use App\Data\Property\PropertySummaryData;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Navigation\AccountNavigation;
use App\Navigation\AdminNavigation;
use App\Navigation\GroupNavigation;
use App\Navigation\PartnerNavigation;
use App\Navigation\PortalNavigation;
use App\Navigation\StaffNavigation;
use App\Services\Channels\ChannelRemovalProgress;
use App\Services\Landing\ServicePages;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Platform\Impersonation;
use App\Services\Staff\GroupProperties;
use App\Services\Staff\StaffProperties;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** The property a staff user works in on pages with none in the URL, found once per request. */
    private ?Property $defaultProperty = null;

    private bool $defaultPropertyResolved = false;

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
            'siteUrl' => ServicePages::url(),
            'auth' => [
                'user' => $request->user(),
            ],
            'impersonation' => fn () => $this->impersonation($request),
            'supportTwoFactor' => RequireSupportTwoFactor::sharedPropFor($request->user()),
            'isDemo' => app()->environment('demo'),
            'docsUrl' => $request->user() !== null ? rtrim((string) config('estatell.docs_url'), '/').'/en/docs' : null,
            'property' => fn () => $this->currentProperty($request),
            'businessDate' => fn () => $this->businessDate($request),
            'adminNavigation' => fn () => $request->user()?->is_admin ? app(AdminNavigation::class)->groups() : null,
            'portalNavigation' => fn () => $request->user()?->is_company_user ? app(PortalNavigation::class)->items($request) : null,
            'portalCompany' => fn () => $this->portalCompany($request),
            'portalSwitcher' => fn () => $request->user()?->is_company_user ? app(PortalNavigation::class)->switcher($request, $request->user()) : null,
            'partnerNavigation' => fn () => $request->user()?->partner_id !== null ? app(PartnerNavigation::class)->items() : null,
            'accountNavigation' => fn () => $request->user() !== null && $request->routeIs('account.*') ? app(AccountNavigation::class)->items($request) : null,
            'navigation' => fn () => $this->staffNavigation($request),
            'propertySwitcher' => fn () => $this->propertySwitcher($request),
            'groupSwitcher' => fn () => $this->groupSwitcher($request),
            'propertyGroup' => fn () => $this->currentGroup($request),
            'channelRemovals' => fn () => app(ChannelRemovalProgress::class)->forUser($request->user()),
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

        $property = $this->resolveProperty($request);

        if ($supportUser === null || $user === null || $property === null) {
            return null;
        }

        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId($property->id);
        }

        return new ImpersonationData(
            supportUserName: $supportUser->name,
            partnerName: $supportUser->partner?->name,
            userName: $user->name,
            userRole: $user->getRoleNames()->first(),
            propertyName: $property->name,
            propertyCode: $property->code,
            minutes: $impersonation->elapsedMinutes(),
            stopUrl: route('impersonation.stop', absolute: false),
            canCorrectMoney: $impersonation->canCorrectMoney(),
        );
    }

    /**
     * The staff sidebar for the property in the URL, or for the property the staff user works in on pages without one.
     * Null for admins and for suspended properties.
     */
    private function staffNavigation(Request $request): ?StaffNavigationData
    {
        $user = $request->user();

        if ($user === null || $user->is_admin || $user->partner_id !== null || $user->is_company_user) {
            return null;
        }

        $group = $this->resolveGroup($request);

        if ($group !== null) {
            return app(GroupNavigation::class)->build($group);
        }

        $property = $this->resolveProperty($request);

        if ($property === null || $property->status === PropertyStatus::Suspended) {
            return null;
        }

        // Pages outside /p (account pages) have no permission team yet: use the property the user works in.
        if (getPermissionsTeamId() === null) {
            setPermissionsTeamId($property->id);
        }

        return app(StaffNavigation::class)->build($property, $user, $request->route()?->getName());
    }

    /**
     * The other properties the staff user may open, for the switcher on the navbar. Empty with one property. In group
     * mode, the group's properties the user may open. See docs/03-staff/04-property-groups.md.
     *
     * @return list<PropertySummaryData>
     */
    private function propertySwitcher(Request $request): array
    {
        $user = $request->user();

        if ($user === null || $user->property_id === null || app(Impersonation::class)->isActive()) {
            return [];
        }

        $groupProperties = $request->attributes->get(EnsureGroupAccess::PROPERTIES);

        if ($groupProperties instanceof Collection) {
            return array_values(array_map(
                fn (Property $property): PropertySummaryData => PropertySummaryData::fromModel($property),
                array_filter($groupProperties->all(), fn ($property): bool => $property instanceof Property),
            ));
        }

        $property = $this->resolveProperty($request);

        if ($property === null) {
            return [];
        }

        return app(StaffProperties::class)->switcherFor($user, $property, $this->activePropertyIds($request));
    }

    /**
     * The groups the staff user may open in group mode (two or more of their properties in one group), for the top of
     * the switcher. No query for a user with one property. See docs/03-staff/05-group-mode.md.
     *
     * @return list<array{id: int, name: string}>
     */
    private function groupSwitcher(Request $request): array
    {
        $user = $request->user();

        if ($user === null || $user->property_id === null || app(Impersonation::class)->isActive()) {
            return [];
        }

        $ids = $this->activePropertyIds($request);

        if ($ids !== null && count($ids) < 2) {
            return [];
        }

        return array_values(app(GroupProperties::class)->groupsFor($user)
            ->map(fn (PropertyGroup $group): array => ['id' => $group->id, 'name' => $group->name])
            ->all());
    }

    /**
     * The group of the page in group mode, for the navbar identity.
     *
     * @return array{id: int, name: string}|null
     */
    private function currentGroup(Request $request): ?array
    {
        $group = $this->resolveGroup($request);

        return $group === null ? null : ['id' => $group->id, 'name' => $group->name];
    }

    /**
     * The ids of the properties the user may open, as `EnsurePropertyAccess` read them for this request, or null on a
     * page it did not run on.
     *
     * @return list<int>|null
     */
    private function activePropertyIds(Request $request): ?array
    {
        $ids = $request->attributes->get(EnsurePropertyAccess::ACTIVE_PROPERTY_IDS);

        return is_array($ids) ? array_values(array_filter($ids, is_int(...))) : null;
    }

    /**
     * The group of a group-mode page (`EnsureGroupAccess` has let the user in), or null.
     */
    private function resolveGroup(Request $request): ?PropertyGroup
    {
        $group = $request->route('propertyGroup');

        return $group instanceof PropertyGroup && $request->attributes->has(EnsureGroupAccess::PROPERTIES) ? $group : null;
    }

    /**
     * The property in the URL, or the property the signed-in staff user works in on pages without one.
     */
    private function currentProperty(Request $request): ?PropertySummaryData
    {
        $property = $this->resolveProperty($request);

        return $property === null ? null : PropertySummaryData::fromModel($property, forGuest: $request->routeIs('concierge.*'));
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
        if ($request->user()?->is_company_user || $this->resolveGroup($request) !== null) {
            return null;
        }

        $property = $request->route('property');

        if ($property instanceof Property) {
            return $property;
        }

        $user = $request->user();

        if ($user === null || $user->property_id === null) {
            return null;
        }

        if (! $this->defaultPropertyResolved) {
            $this->defaultProperty = app(StaffProperties::class)->defaultFor($user);
            $this->defaultPropertyResolved = true;
        }

        return $this->defaultProperty;
    }

    /**
     * The company and property of the open `/portal/{code}` page, from the link `EnsurePortalAccess` found.
     *
     * @return array{companyName: string, propertyName: string, propertyCode: string, logoUrl: string|null}|null
     */
    private function portalCompany(Request $request): ?array
    {
        $link = $request->attributes->get('portalLink');

        return $link instanceof CompanyUser
            ? ['companyName' => $link->company->name, 'propertyName' => $link->property->name, 'propertyCode' => $link->property->code, 'logoUrl' => PortalSwitcherData::logoUrl($link->property)]
            : null;
    }

    /** @return list<NavItemData> */
    private function commandShortcuts(Request $request): array
    {
        $user = $request->user();
        $property = $user?->is_company_user ? null : $this->resolveProperty($request);

        if ($user === null || $property === null || $property->status !== PropertyStatus::Live) {
            return [];
        }

        setPermissionsTeamId($property->id);

        return $user->can(PermissionEnum::ReservationsManage->value) ? [
            new NavItemData(__('New booking'), route('reservations.new', ['property' => $property->code], absolute: false), 'calendar-check'),
        ] : [];
    }
}
