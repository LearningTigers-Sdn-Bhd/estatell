<?php

namespace App\Enums;

/**
 * A quick date of a range report, counted from the business date. `ReportKey::datePresets()` picks the list of a
 * report, and the browser works out the dates and the labels (`resources/js/lib/date-presets.ts`).
 * See docs/22-reports/01-core-reports.md.
 */
enum ReportDatePreset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Tomorrow = 'tomorrow';
    case Next7Days = 'next-7-days';
    case ThisWeek = 'this-week';
    case LastWeek = 'last-week';
    case NextWeek = 'next-week';
    case ThisMonth = 'this-month';
    case LastMonth = 'last-month';
    case ThisYear = 'this-year';
    case Last30Days = 'last-30-days';
}
