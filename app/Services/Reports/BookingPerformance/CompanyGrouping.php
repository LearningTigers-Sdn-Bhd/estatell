<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\Company;

/**
 * By the company the booking is billed to. See docs/22-reports/02-guest-list-and-daily-report.md#5-booking-performance.
 */
class CompanyGrouping extends NamedRecordGrouping
{
    protected function column(): string
    {
        return 'company_id';
    }

    protected function model(): string
    {
        return Company::class;
    }

    public function noneLabel(): string
    {
        return __('No company');
    }
}
