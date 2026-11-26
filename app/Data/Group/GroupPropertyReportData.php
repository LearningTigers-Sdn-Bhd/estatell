<?php

namespace App\Data\Group;

use App\Data\Property\CurrencyData;
use App\Data\Reports\ReportLayoutColumnData;
use Spatie\LaravelData\Data;

/**
 * A property report run for every property of the group: the columns (every property's visible columns, in the
 * report's order), the rows of all properties (one page of `PER_PAGE` on screen, `page` of `pages`; the CSV and the PDF
 * have them all), a total per
 * property and for all of them when the report has totals, and who was left out. `currency` is set when every
 * property uses one; money totals across properties need it.
 */
class GroupPropertyReportData extends Data
{
    public const int PER_PAGE = 50;

    /**
     * @param  list<ReportLayoutColumnData>  $columns
     * @param  list<GroupReportRowData>  $rows
     * @param  list<GroupReportRowData>  $totals
     * @param  list<string>  $leftOut  No reports permission there.
     * @param  list<string>  $notOffered  The report needs a feature the property does not use.
     */
    public function __construct(
        public string $report,
        public string $label,
        public array $columns,
        public array $rows,
        public int $rowCount,
        public array $totals,
        public ?CurrencyData $currency,
        public array $leftOut,
        public array $notOffered,
        public int $page = 1,
        public int $pages = 1,
        public int $perPage = self::PER_PAGE,
    ) {}
}
