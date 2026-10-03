<?php

namespace App\Enums;

/**
 * Who made a folio routing rule: `SyncRoutingRules` from the billing mode, or staff by hand. The billing mode never
 * touches Manual rules. See docs/13-folio/03-routing.md.
 */
enum RoutingRuleSource: string
{
    case BillingMode = 'billing_mode';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::BillingMode => __('Billing mode'),
            self::Manual => __('Manual'),
        };
    }
}
