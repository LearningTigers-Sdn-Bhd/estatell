<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use App\Services\Commissions\PropertyPayouts;

/**
 * Sidebar groups for the Finance context. Items the user has no permission for are left out.
 * See docs/design-system/09-app-shell.md.
 */
class FinanceNavigation
{
    public function __construct(private PropertyPayouts $payouts) {}

    /**
     * @return list<NavGroupData>
     */
    public function groups(Property $property, User $user): array
    {
        return [
            new NavGroupData(label: __('City ledger'), items: array_values(array_filter([
                $user->can(PermissionEnum::CompanyView->value) ? new NavItemData(
                    title: __('Companies'),
                    href: route('finance.city-ledger.companies.index', ['property' => $property->code], absolute: false),
                    icon: 'building',
                    active: request()->routeIs('finance.city-ledger.companies.*'),
                ) : null,
                $user->can(PermissionEnum::CompanyView->value) ? new NavItemData(
                    title: __('Invoices'),
                    href: route('finance.city-ledger.invoices.index', ['property' => $property->code], absolute: false),
                    icon: 'receipt',
                    active: request()->routeIs('finance.city-ledger.invoices.*'),
                ) : null,
                $user->can(PermissionEnum::CompanyView->value) ? new NavItemData(
                    title: __('Aging'),
                    href: route('finance.city-ledger.aging', ['property' => $property->code], absolute: false),
                    icon: 'chart-column',
                    active: request()->routeIs('finance.city-ledger.aging*'),
                ) : null,
            ]))),
            new NavGroupData(label: __('Estatell'), items: $user->can(PermissionEnum::PayoutsView->value) && $this->payouts->isShown($property) ? [
                new NavItemData(
                    title: __('Payouts'),
                    href: route('finance.payouts.index', ['property' => $property->code], absolute: false),
                    icon: 'banknote',
                    active: request()->routeIs('finance.payouts.*'),
                ),
            ] : []),
        ];
    }
}
