<?php

namespace App\Enums;

/**
 * The tabs of the property dashboard. Performance is the default for users with `reports.view`. Today is the only tab without it.
 * See docs/18-front-desk/06-dashboard-performance.md.
 */
enum DashboardTab: string
{
    case Performance = 'performance';
    case Today = 'today';

    public function label(): string
    {
        return match ($this) {
            self::Performance => __('Performance'),
            self::Today => __('Today'),
        };
    }

    /**
     * The tab of a URL value. A missing or unknown value is Performance.
     */
    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Performance) : self::Performance;
    }
}
