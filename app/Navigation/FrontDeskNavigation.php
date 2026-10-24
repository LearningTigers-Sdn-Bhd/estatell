<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;

/**
 * Sidebar groups for the Front desk context. Items the user has no permission for are left out.
 * See docs/design-system/09-app-shell.md.
 */
class FrontDeskNavigation
{
    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        return [
            new NavGroupData(label: __('Overview'), items: array_filter([
                new NavItemData(
                    title: __('Dashboard'),
                    href: route('dashboard', ['property' => $property->code], absolute: false),
                    icon: 'layout-grid',
                    active: request()->routeIs('dashboard'),
                ),
                $user->can(PermissionEnum::FrontDeskView->value) ? new NavItemData(
                    title: __('Timeline View'),
                    href: route('front-desk.timeline', ['property' => $property->code], absolute: false),
                    icon: 'chart-gantt',
                    active: request()->routeIs('front-desk.timeline'),
                ) : null,
            ])),
            new NavGroupData(label: __('Rooms'), items: array_values(array_filter([
                $user->can(PermissionEnum::RoomsBlock->value) ? new NavItemData(
                    title: __('Room blocks'),
                    href: route('front-desk.room-blocks.index', ['property' => $property->code], absolute: false),
                    icon: 'ban',
                    active: request()->routeIs('front-desk.room-blocks.*'),
                ) : null,
                $user->can(PermissionEnum::HousekeepingView->value) ? new NavItemData(
                    title: __('Housekeeping'),
                    href: route('housekeeping.index', ['property' => $property->code], absolute: false),
                    icon: 'spray-can',
                    active: request()->routeIs('housekeeping.*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Reservations'), items: array_filter([
                $user->can(PermissionEnum::ReservationsView->value) ? new NavItemData(
                    title: __('All reservations'),
                    href: route('reservations.index', ['property' => $property->code], absolute: false),
                    icon: 'calendar-check',
                    active: request()->routeIs('reservations.*'),
                ) : null,
            ])),
            new NavGroupData(label: __('Guests'), items: array_filter([
                $user->can(PermissionEnum::GuestsView->value) ? new NavItemData(
                    title: __('Guests'),
                    href: route('guests.index', ['property' => $property->code], absolute: false),
                    icon: 'users',
                    active: request()->routeIs('guests.*'),
                ) : null,
            ])),
            new NavGroupData(label: __('Operations'), items: array_values(array_filter([
                $user->can(PermissionEnum::RatesView->value) ? new NavItemData(
                    title: __('Rate calendar'),
                    href: route('rates.calendar', ['property' => $property->code], absolute: false),
                    icon: 'calendar-range',
                    active: request()->routeIs('rates.*'),
                ) : null,
                $property->status === PropertyStatus::Live && $user->can(PermissionEnum::NightAuditRun->value) ? new NavItemData(
                    title: __('Run Night Audit'),
                    href: route('night-audit.show', ['property' => $property->code], absolute: false),
                    icon: 'moon',
                    action: 'night-audit',
                ) : null,
            ]))),
        ];
    }
}
