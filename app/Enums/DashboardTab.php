<?php

namespace App\Enums;

/**
 * The tabs of the property dashboard. Today is the default and is the only tab without `reports.view`.
 * See docs/18-front-desk/06-dashboard-performance.md.
 */
enum DashboardTab: string
{
    case Today = 'today';
    case Performance = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::Today => __('Today'),
            self::Performance => __('Performance'),
        };
    }

    /**
     * The tab of a URL value. A missing or unknown value is Today.
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Today) : self::Today;
    }
}
