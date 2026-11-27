<?php

namespace App\Data\Group;

use App\Data\Property\CurrencyData;
use App\Data\Property\PropertySummaryData;
use App\Data\Reports\RoomStatsChartData;
use Spatie\LaravelData\Data;

/**
 * The open tab of Group reports: the properties it covers (where the role may view reports) and its rows. By property
 * and By date end with a total row; Bookings has none. `currency` is set when every property uses one. `leftOut`
 * names the group's properties the user may open but whose role there does not show reports.
 */
class GroupReportsData extends Data
{
    /**
     * @param  list<PropertySummaryData>  $properties
     * @param  list<GroupFiguresRowData>  $figures
     * @param  list<GroupBookingChangeRowData>  $bookings
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public array $properties,
        public ?CurrencyData $currency,
        public array $figures,
        public ?GroupFiguresRowData $total,
        public array $bookings,
        public array $leftOut,
        public ?RoomStatsChartData $chart = null,
    ) {}
}
