<?php

namespace App\Enums;

/**
 * What happened when a rate plan's online switch was set.
 */
enum BookableOnlineResult: string
{
    case Saved = 'saved';
    case NotAvailable = 'not-available';
    case LastPlan = 'last-plan';

    public function message(): string
    {
        return match ($this) {
            self::Saved => __('Saved.'),
            self::NotAvailable => __('The rate plans changed. Reload the page and try again.'),
            self::LastPlan => __('Keep at least one plan bookable while the site is published.'),
        };
    }
}
