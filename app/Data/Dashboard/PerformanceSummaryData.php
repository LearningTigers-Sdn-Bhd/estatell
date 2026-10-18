<?php

namespace App\Data\Dashboard;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

/**
 * The figures of the Performance tab for one period and the range it is compared with.
 */
class PerformanceSummaryData extends Data
{
    /**
     * @param  bool  $hasStays  False when no live unit has a night or an arrival in the range: every figure shows "–".
     * @param  bool  $hasAuditFigures  False when the range has stays but no night audit figures: occupancy, ADR and RevPAR are null.
     * @param  list<PerformanceTrendPointData>  $trend
     */
    public function __construct(
        public ?CurrencyData $currency,
        public bool $hasStays,
        public bool $hasAuditFigures,
        public PerformanceKpiData $occupancy,
        public PerformanceKpiData $adr,
        public PerformanceKpiData $revpar,
        public LeadTimeData $leadTime,
        public PerformanceChannelMixData $channelMix,
        public array $trend,
    ) {}
}
