<?php

namespace App\Services\Reports;

use App\Data\Reports\BookingPerformanceRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportTotals;
use App\Data\Reservations\BookingSourceBadgeData;
use App\Data\Shared\BadgeData;
use App\Enums\BookingSourceType;
use App\Enums\ReportGroup;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\BookingSource;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bookings made in the dates (by `reservations.created_at` in the property time zone), with their room nights, room
 * revenue (`unit_nights.amount`), ADR and lead time, grouped by room type, source, rate plan, status or booking date.
 * Only overnight units count. A booking with units in two groups counts in both, and once in the total. Cancelled and
 * no-show units count only when grouped by status. Walk-ins have no lead time. See
 * docs/22-reports/02-guest-list-and-daily-report.md#5-booking-performance.
 */
class BookingPerformanceReport implements HasTotals, Report
{
    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<BookingPerformanceRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $grouping = $filters->group ?? ReportGroup::RoomType;
        $units = $this->units($property, $filters, $grouping);

        /** @var array<string, array{id: int|string|null, reservationIds: array<int, true>, nights: int, revenue: BigDecimal, leadDays: int, leadUnits: int}> $buckets */
        $buckets = [];

        foreach ($units as $unit) {
            $id = match ($grouping) {
                ReportGroup::Source => (int) $unit->booking_source_id,
                ReportGroup::RatePlan => $unit->rate_plan_id === null ? null : (int) $unit->rate_plan_id,
                ReportGroup::Status => (string) $unit->status,
                ReportGroup::Day => (string) $unit->booked_on,
                default => (int) $unit->room_type_id,
            };
            $key = $grouping->value.':'.($id ?? 'none');
            $bucket = $buckets[$key] ?? ['id' => $id, 'reservationIds' => [], 'nights' => 0, 'revenue' => BigDecimal::zero(), 'leadDays' => 0, 'leadUnits' => 0];
            $bucket['reservationIds'][(int) $unit->reservation_id] = true;
            $bucket['nights'] += (int) $unit->nights;
            $bucket['revenue'] = $bucket['revenue']->plus((string) $unit->amount);

            if (! $unit->is_walk_in) {
                $bucket['leadDays'] += (int) $unit->lead_days;
                $bucket['leadUnits']++;
            }

            $buckets[$key] = $bucket;
        }

        if ($rowKeys !== null) {
            $buckets = array_intersect_key($buckets, array_flip(array_map('strval', $rowKeys)));
        }

        $names = $this->names($property, $grouping, array_column($buckets, 'id'));
        $rows = [];

        foreach ($buckets as $key => $bucket) {
            $id = $bucket['id'];
            $reservationIds = array_keys($bucket['reservationIds']);
            $source = $grouping === ReportGroup::Source ? $names->get($id) : null;
            $status = $grouping === ReportGroup::Status ? UnitStatus::from((string) $id) : null;

            $rows[] = new BookingPerformanceRow(
                key: $key,
                grouping: $grouping,
                group: match (true) {
                    $source instanceof BookingSource => $source->name,
                    $status !== null => $status->label(),
                    $grouping === ReportGroup::Day => (string) $id,
                    $id === null => __('No rate plan'),
                    default => (string) $names->get($id, ''),
                },
                bookings: count($reservationIds),
                room_nights: $bucket['nights'],
                revenue: (string) $bucket['revenue']->toScale(2, RoundingMode::HalfUp),
                adr: $this->adr($bucket['revenue'], $bucket['nights']),
                lead_time: $this->leadTime($bucket['leadDays'], $bucket['leadUnits']),
                source: $source instanceof BookingSource ? BookingSourceBadgeData::fromModel($source) : null,
                status: $status === null ? null : BadgeData::fromUnitStatus($status),
                reservationIds: $reservationIds,
                leadDays: $bucket['leadDays'],
                leadUnits: $bucket['leadUnits'],
            );
        }

        return $rows;
    }

    /**
     * Distinct bookings, so a booking in two rows counts once. ADR and lead time are worked out again from the sums.
     *
     * @param  list<BookingPerformanceRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        $revenue = array_reduce($rows, fn (BigDecimal $sum, BookingPerformanceRow $row): BigDecimal => $sum->plus($row->revenue), BigDecimal::zero());
        $nights = array_sum(array_map(fn (BookingPerformanceRow $row): int => $row->room_nights, $rows));
        $reservationIds = array_unique(array_merge([], ...array_map(fn (BookingPerformanceRow $row): array => $row->reservationIds, $rows)));

        return new ReportTotals([
            'group' => null,
            'bookings' => count($reservationIds),
            'room_nights' => $nights,
            'revenue' => (string) $revenue->toScale(2, RoundingMode::HalfUp),
            'adr' => $this->adr($revenue, $nights),
            'lead_time' => $this->leadTime(
                array_sum(array_map(fn (BookingPerformanceRow $row): int => $row->leadDays, $rows)),
                array_sum(array_map(fn (BookingPerformanceRow $row): int => $row->leadUnits, $rows)),
            ),
        ]);
    }

    /**
     * One row per overnight unit of the bookings made in the dates, with its room nights and room revenue. The dates
     * are turned into the stored (app time zone) bounds, so the `created_at` index is used.
     *
     * @return Collection<int, object{reservation_id: int, room_type_id: int, rate_plan_id: int|null, status: string, booking_source_id: int, booked_on: string, lead_days: int, is_walk_in: bool, nights: int, amount: string}>
     */
    private function units(Property $property, ReportFilters $filters, ReportGroup $grouping): Collection
    {
        $appTimeZone = config('app.timezone');
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? $appTimeZone;
        $start = CarbonImmutable::parse($filters->from, $timeZone)->startOfDay()->setTimezone($appTimeZone);
        $end = CarbonImmutable::parse($filters->to, $timeZone)->addDay()->startOfDay()->setTimezone($appTimeZone);

        $nights = DB::table('unit_nights')
            ->groupBy('reservation_unit_id')
            ->selectRaw('reservation_unit_id, COUNT(*) as nights, COALESCE(SUM(amount), 0) as amount');

        $bookedOn = '((reservations.created_at AT TIME ZONE ?) AT TIME ZONE ?)::date';

        /** @var Collection<int, object{reservation_id: int, room_type_id: int, rate_plan_id: int|null, status: string, booking_source_id: int, booked_on: string, lead_days: int, is_walk_in: bool, nights: int, amount: string}> */
        return DB::table('reservation_units')
            ->join('reservations', 'reservations.id', '=', 'reservation_units.reservation_id')
            ->join('booking_sources', 'booking_sources.id', '=', 'reservations.booking_source_id')
            ->leftJoinSub($nights, 'nights', 'nights.reservation_unit_id', '=', 'reservation_units.id')
            ->where('reservation_units.property_id', $property->id)
            ->where('reservations.property_id', $property->id)
            ->where('reservation_units.stay_type', StayType::Overnight->value)
            ->where('reservations.created_at', '>=', $start->toDateTimeString())
            ->where('reservations.created_at', '<', $end->toDateTimeString())
            ->when($grouping !== ReportGroup::Status, fn ($query) => $query->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory())))
            ->selectRaw(
                "reservation_units.reservation_id, reservation_units.room_type_id, reservation_units.rate_plan_id, reservation_units.status, reservations.booking_source_id, {$bookedOn} as booked_on, GREATEST(0, reservation_units.arrival_date - {$bookedOn}) as lead_days, (booking_sources.type = ?) as is_walk_in, COALESCE(nights.nights, 0) as nights, COALESCE(nights.amount, 0) as amount",
                [$appTimeZone, $timeZone, $appTimeZone, $timeZone, BookingSourceType::WalkIn->value],
            )
            ->get();
    }

    /**
     * The names of the room types or rate plans, or the booking sources for their badges, by id.
     *
     * @param  list<int|string|null>  $ids
     * @return Collection<int|string, mixed>
     */
    private function names(Property $property, ReportGroup $grouping, array $ids): Collection
    {
        $ids = array_values(array_filter($ids, fn (int|string|null $id): bool => is_int($id)));

        return match ($grouping) {
            ReportGroup::RoomType => RoomType::query()->where('property_id', $property->id)->whereIn('id', $ids)->pluck('name', 'id'),
            ReportGroup::RatePlan => RatePlan::query()->where('property_id', $property->id)->whereIn('id', $ids)->pluck('name', 'id'),
            ReportGroup::Source => BookingSource::query()->where('property_id', $property->id)->whereIn('id', $ids)->get()->keyBy('id'),
            default => collect(),
        };
    }

    private function adr(BigDecimal $revenue, int $nights): ?string
    {
        return $nights === 0 ? null : (string) $revenue->dividedBy($nights, 2, RoundingMode::HalfUp);
    }

    private function leadTime(int $days, int $units): ?int
    {
        return $units === 0 ? null : BigDecimal::of($days)->dividedBy($units, 0, RoundingMode::HalfUp)->toInt();
    }
}
