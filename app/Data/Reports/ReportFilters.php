<?php

namespace App\Data\Reports;

use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The checked filters of a report: the first and last date (`Y-m-d`, both included) and the extra filters that only
 * some reports take. `group` is set only for a report that groups its rows (Revenue, Tax) and `code` only for a report
 * that filters by transaction code (Folio ledger).
 */
class ReportFilters extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        public ?ReportGroup $group = null,
        public ?string $code = null,
    ) {}

    /**
     * The number of days in the range, counting both ends.
     */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /**
     * The filters that go back into the page URL, the exports and the layout preview, without the sort or the page.
     * A one-date report keeps its single `date`.
     *
     * @return array<string, string>
     */
    public function query(bool $isOneDate): array
    {
        return [
            ...($isOneDate ? ['date' => $this->from] : ['from' => $this->from, 'to' => $this->to]),
            ...($this->group === null ? [] : ['group' => $this->group->value]),
            ...($this->code === null ? [] : ['code' => $this->code]),
        ];
    }
}
