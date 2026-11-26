<?php

namespace App\Services\Group;

use App\Data\Reports\HistoryForecastRow;
use App\Data\Reports\ReportFilters;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Reports\HistoryForecastReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A property's History & forecast rows month by month, kept in the cache, so the group Forecast does not price every
 * night again when the owner goes from 12 to 24 months: only the months not in the cache are worked out, in one call.
 * Pricing stays exact (`ChargeForecast` quotes each night as the night audit will). A month is kept for
 * `TTL_MINUTES` under the property's data version, which changes with the business date, any stay, add-on or night
 * price, any folio line and any tax, so a change shows at once. See docs/03-staff/05-group-mode.md#forecast.
 */
class ForecastMonthCache
{
    public const int TTL_MINUTES = 30;

    public function __construct(private HistoryForecastReport $historyForecast, private CurrentBusinessDate $businessDate) {}

    /**
     * The rows of every date from the first day of `$from`'s month to the last day of `$to`'s month, in date order.
     *
     * @return list<HistoryForecastRow>
     */
    public function rows(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $version = $this->version($property);
        $months = [];

        for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = "group-forecast:{$property->id}:{$version}:".$month->format('Y-m');
        }

        // Kept as plain arrays: the cache does not unserialize objects.
        /** @var array<string, list<array<string, mixed>>|null> $cached */
        $cached = Cache::many(array_values($months));
        $missing = array_keys(array_filter($months, fn (string $key): bool => ($cached[$key] ?? null) === null));

        if ($missing !== []) {
            $first = CarbonImmutable::parse($missing[0].'-01');
            $last = CarbonImmutable::parse(end($missing).'-01')->endOfMonth();
            $fresh = [];

            foreach ($this->historyForecast->rows($property, new ReportFilters($first->toDateString(), $last->toDateString())) as $row) {
                $fresh[substr($row->date, 0, 7)][] = $row->toArray();
            }

            $put = [];

            foreach ($missing as $month) {
                $put[$months[$month]] = $cached[$months[$month]] = $fresh[$month] ?? [];
            }

            Cache::putMany($put, now()->addMinutes(self::TTL_MINUTES));
        }

        $rows = [];

        foreach ($months as $key) {
            foreach ($cached[$key] ?? [] as $row) {
                $rows[] = HistoryForecastRow::from($row);
            }
        }

        return $rows;
    }

    /**
     * The rows of the dates from `$from` to `$to`, read through the months that cover them.
     *
     * @return list<HistoryForecastRow>
     */
    public function between(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = $from->toDateString();
        $last = $to->toDateString();

        return array_values(array_filter(
            $this->rows($property, $from->startOfMonth(), $to->endOfMonth()),
            fn (HistoryForecastRow $row): bool => $row->date >= $first && $row->date <= $last,
        ));
    }

    /**
     * What the forecast of a property is worked out from, in one short read: when any of it changes, every cached
     * month of the property is left behind.
     */
    public function version(Property $property): string
    {
        $state = DB::selectOne(<<<'SQL'
            select
                (select max(updated_at) from reservation_units where property_id = :p1) as units,
                (select max(a.updated_at) from unit_add_ons a join reservation_units u on u.id = a.reservation_unit_id where u.property_id = :p2) as add_ons,
                (select count(*) || ':' || coalesce(sum(n.amount), 0) || ':' || coalesce(max(n.id), 0) from unit_nights n join reservation_units u on u.id = n.reservation_unit_id where u.property_id = :p3) as nights,
                (select max(l.id) from folio_lines l join folios f on f.id = l.folio_id where f.property_id = :p4) as lines,
                (select max(updated_at) from taxes where property_id = :p5) as taxes
            SQL, ['p1' => $property->id, 'p2' => $property->id, 'p3' => $property->id, 'p4' => $property->id, 'p5' => $property->id]);

        return md5(implode('|', [$this->businessDate->date($property)?->toDateString(), ...array_values((array) $state)]));
    }
}
