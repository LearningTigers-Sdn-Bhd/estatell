<?php

namespace App\Data\Reports;

use App\Enums\BoatDirection;
use App\Enums\ChargeBucket;
use App\Enums\ReportGroup;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The checked filters of a report: the first and last date (`Y-m-d`, both included) and the extra filters that only
 * some reports take. `group` is set only for a report that groups its rows (Revenue, Tax) and `code` only for a report
 * that filters by transaction code (Folio ledger). `staff` (a user id) and `refundsOnly` are set only for Payments, `foreignOnly` only for the Police report, and `direction` only for the Boat manifest.
 * `bucket` and `search` (a booking number or guest name) are set only for Daily report › Charges.
 */
class ReportFilters extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        public ?ReportGroup $group = null,
        public ?string $code = null,
        public ?int $staff = null,
        public bool $refundsOnly = false,
        public bool $foreignOnly = false,
        public ?BoatDirection $direction = null,
        public ?ChargeBucket $bucket = null,
        public ?string $search = null,
    ) {}

    /**
     * The number of days in the range, counting both ends.
     */
    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }

    /**
     * The first date of a range of at most `$maxDays` days that ends on `to`: `from` when the range is short enough,
     * else `to` minus `$maxDays - 1` days. A link from a longer range to a shorter report uses it.
     */
    public function clampedFrom(int $maxDays): string
    {
        return $this->days() > $maxDays
            ? CarbonImmutable::parse($this->to)->subDays($maxDays - 1)->toDateString()
            : $this->from;
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
            ...($this->staff === null ? [] : ['staff' => (string) $this->staff]),
            ...($this->refundsOnly ? ['refunds' => '1'] : []),
            ...($this->foreignOnly ? ['foreign' => '1'] : []),
            ...($this->direction === null ? [] : ['direction' => $this->direction->value]),
            ...($this->bucket === null ? [] : ['bucket' => $this->bucket->value]),
            ...($this->search === null ? [] : ['search' => $this->search]),
        ];
    }
}
