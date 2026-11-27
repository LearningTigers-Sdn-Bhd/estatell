<?php

namespace App\Data\Group;

use App\Data\Property\CurrencyData;
use App\Data\Property\PropertySummaryData;
use Spatie\LaravelData\Data;

/**
 * The group forecast: the months (`Y-m`), the properties it covers, a row per property and month and a row per month
 * for all of them (`code` null), the currency when every property has the same one, and the properties left out
 * because the role there does not show reports.
 */
class GroupForecastData extends Data
{
    /**
     * @param  list<string>  $months
     * @param  list<PropertySummaryData>  $properties
     * @param  list<GroupForecastRowData>  $rows
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public array $months,
        public array $properties,
        public array $rows,
        public ?CurrencyData $currency,
        public array $leftOut,
    ) {}
}
