<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The staff areas (sidebar contexts). The active context comes from the current route name.
 */
enum NavigationContext: string
{
    case FrontDesk = 'front-desk';
    case Finance = 'finance';
    case Reports = 'reports';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::FrontDesk => __('Front desk'),
            self::Finance => __('Finance'),
            self::Reports => __('Reports'),
            self::Settings => __('Settings'),
        };
    }

    /**
     * Lucide icon name, mapped to a component in the frontend.
     */
    public function icon(): string
    {
        return match ($this) {
            self::FrontDesk => 'concierge-bell',
            self::Finance => 'wallet',
            self::Reports => 'chart-column',
            self::Settings => 'settings',
        };
    }

    /**
     * Route name patterns that belong to this context. See docs/routing.md.
     *
     * @return list<string>
     */
    public function routePatterns(): array
    {
        return match ($this) {
            self::FrontDesk => ['dashboard', 'front-desk.*', 'reservations.*', 'guests.*', 'housekeeping.*', 'rates.*', 'night-audit.*'],
            self::Finance => ['finance.*'],
            self::Reports => ['reports.*'],
            self::Settings => ['settings.*', 'channels.*'],
        };
    }

    public static function forRoute(?string $routeName): ?self
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::cases() as $context) {
            if (Str::is($context->routePatterns(), $routeName)) {
                return $context;
            }
        }

        return null;
    }
}
