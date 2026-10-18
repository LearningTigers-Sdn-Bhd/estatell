<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * Room nights of live units by stay date, by booking source and by source type. Both views are sent so the switch
 * makes no request.
 */
class PerformanceChannelMixData extends Data
{
    /**
     * @param  string  $amount  Room total of all nights. Decimal string.
     * @param  list<ChannelMixRowData>  $bySource  Largest first.
     * @param  list<ChannelMixRowData>  $byType  Largest first, only types with nights.
     */
    public function __construct(
        public int $nights,
        public string $amount,
        public array $bySource,
        public array $byType,
    ) {}
}
