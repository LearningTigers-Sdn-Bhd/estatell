<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\RatePlan;

/**
 * By rate plan. A unit without a plan goes in **No rate plan**.
 */
class RatePlanGrouping extends NamedRecordGrouping
{
    protected function column(): string
    {
        return 'rate_plan_id';
    }

    protected function model(): string
    {
        return RatePlan::class;
    }

    public function noneLabel(): string
    {
        return __('No rate plan');
    }
}
