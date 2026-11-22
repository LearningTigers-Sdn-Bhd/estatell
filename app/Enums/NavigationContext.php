<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The staff areas (sidebar contexts). The active context comes from the current route name. Group is the only
 * context of group mode (`GroupNavigation`) and never shows inside a property.
 */
enum NavigationContext: string
{
    case FrontDesk = 'front-desk';
    case Pos = 'pos';
    case Finance = 'finance';
    case Reports = 'reports';
    case Settings = 'settings';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::FrontDesk => __('Front desk'),
            self::Pos => __('POS'),
            self::Finance => __('Finance'),
            self::Reports => __('Reports'),
            self::Settings => __('Settings'),
            self::Group => __('Group'),
        };
    }

    /**
     * Lucide icon name, mapped to a component in the frontend.
     */
    public function icon(): string
    {
        return match ($this) {
            self::FrontDesk => 'concierge-bell',
            self::Pos => 'utensils',
            self::Finance => 'wallet',
            self::Reports => 'chart-column',
            self::Settings => 'settings',
            self::Group => 'network',
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
            self::FrontDesk => [
                'dashboard',
                'front-desk.*',
                'reservations.*',
                'guests.*',
                'housekeeping.*',
                'guest-requests.*',
                'boats.*',
                'rates.*',
                'night-audit.*',
                'reports.house-status',
            ],
            self::Pos => [
                'pos.*',
                'restaurant.meal-check-in.*',
                'settings.pos-outlets.*',
                'settings.pos-api-clients.*',
                'settings.pos.*',
            ],
            self::Finance => ['finance.*'],
            self::Reports => ['reports.*'],
            self::Settings => ['settings.*', 'channels.*'],
            self::Group => ['group.*'],
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
