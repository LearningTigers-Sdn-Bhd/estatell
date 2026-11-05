<?php

namespace App\Navigation;

use App\Data\Navigation\NavGroupData;
use App\Data\Navigation\NavItemData;
use App\Enums\PermissionEnum;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Sidebar groups for the Finance context. Items the user has no permission for are left out.
 * See docs/design-system/09-app-shell.md.
 */
class FinanceNavigation
{
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
            ...(Feature::for($property)->active(EInvoicing::class) && $user->can(PermissionEnum::EInvoiceView->value) ? [
                new NavGroupData(label: __('LHDN MyInvois'), items: [
                    new NavItemData(
                        title: __('Submissions'),
                        href: route('finance.e-invoices.index', ['property' => $property->code], absolute: false),
                        icon: 'file-check',
                        active: request()->routeIs('finance.e-invoices.*'),
                    ),
                ]),
            ] : []),
        ];
    }
}
