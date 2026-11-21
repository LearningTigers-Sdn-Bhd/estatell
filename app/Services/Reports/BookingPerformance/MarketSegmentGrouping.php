<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\MarketSegment;

/**
 * By market segment. See docs/12-reservations/15-market-segments.md.
 */
class MarketSegmentGrouping extends NamedRecordGrouping
{
    protected function column(): string
    {
        return 'market_segment_id';
    }

    protected function model(): string
    {
        return MarketSegment::class;
    }

    public function noneLabel(): string
    {
        return __('No market segment');
    }
}
