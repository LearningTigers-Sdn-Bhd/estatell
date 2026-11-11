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
        $cityLedger = array_values(array_filter([
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
        ]));

        return [
            new NavGroupData(label: __('City ledger'), items: $cityLedger),
            new NavGroupData(label: __('Estatell'), items: $this->showsPayouts($property, $user, $cityLedger) ? [
                new NavItemData(
                    title: __('Payouts'),
                    href: route('finance.payouts.index', ['property' => $property->code], absolute: false),
                    icon: 'banknote',
                    active: request()->routeIs('finance.payouts.*'),
                ),
            ] : []),
        ];
    }

    /**
     * Whether the Payouts item is listed. Its visibility check runs queries, so it runs only on Finance pages, or when
     * Payouts alone decides whether the user has a Finance area at all. Elsewhere the Finance sidebar is not drawn.
     *
     * @param  list<NavItemData>  $cityLedger
     */
    private function showsPayouts(Property $property, User $user, array $cityLedger): bool
    {
        if (! $user->can(PermissionEnum::PayoutsView->value)) {
            return false;
        }

        if ($cityLedger !== [] && ! request()->routeIs('finance.*')) {
            return false;
        }

        return $this->payouts->isShown($property);
    }
}
