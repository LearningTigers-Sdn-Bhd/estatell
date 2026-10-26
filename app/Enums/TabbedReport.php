<?php

namespace App\Enums;

/**
 * A report page with tabs, each tab being one report of the registry. The value is the URL slug
 * (`/p/{code}/reports/guest-list`) and the route name suffix. The tab is `?tab` in the URL.
 * See docs/22-reports/02-guest-list-and-daily-report.md.
 */
enum TabbedReport: string
{
    case GuestList = 'guest-list';
    case DailyReport = 'daily-report';

    public function label(): string
    {
        return match ($this) {
            self::GuestList => __('Guest list'),
            self::DailyReport => __('Daily report'),
        };
    }

    public function category(): ReportCategory
    {
        return match ($this) {
            self::GuestList => ReportCategory::FrontDesk,
            self::DailyReport => ReportCategory::Revenue,
        };
    }

    /**
     * The sidebar icon name, one of the names in `resources/js/lib/navigation.ts`.
     */
    public function icon(): string
    {
        return match ($this) {
            self::GuestList => 'users',
            self::DailyReport => 'calculator',
        };
    }

    /**
     * The tabs in the order shown, each `?tab` value with the report whose dates, filters, layout and exports it uses.
     * The first tab is the default. Daily report › Overview has no table and uses the Daily revenue dates.
     *
     * @return array<string, ReportKey>
     */
    public function tabs(): array
    {
        return match ($this) {
            self::GuestList => [
                'arrivals' => ReportKey::Arrivals,
                'in-house' => ReportKey::InHouse,
                'departures' => ReportKey::Departures,
                'checked-out' => ReportKey::CheckedOut,
            ],
            self::DailyReport => [
                'overview' => ReportKey::DailyRevenue,
                'revenue' => ReportKey::DailyRevenue,
                'charges' => ReportKey::DailyCharges,
                'cashier' => ReportKey::Payments,
            ],
        };
    }

    /**
     * The tab for a `?tab` value: the value when it is a tab, else the first tab.
     */
    public function tab(?string $value): string
    {
        $tabs = $this->tabs();

        return $value !== null && array_key_exists($value, $tabs) ? $value : array_key_first($tabs);
    }

    /**
     * The report of a `?tab` value, the first tab's report when the value is not a tab.
     */
    public function reportKey(?string $tab): ReportKey
    {
        return $this->tabs()[$this->tab($tab)];
    }

    /**
     * The route names the sidebar entry is active on: the page, and the own page of each tab report that is not
     * listed in the sidebar by itself.
     *
     * @return list<string>
     */
    public function activeRouteNames(): array
    {
        $keys = array_filter($this->tabs(), fn (ReportKey $key): bool => ! $key->listedInReportsMenu());

        return array_values(array_unique(['reports.'.$this->value, ...array_map(fn (ReportKey $key): string => 'reports.'.$key->value, array_values($keys))]));
    }
}
