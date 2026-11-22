<?php

namespace App\Services\Reports;

use App\Data\Reports\BookingChangeRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Enums\BookingChangeKind;
use App\Enums\StayType;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Reservations\ReservationRoomBox;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Rooms on books › Changes (Tanini's "made on" lists, plan items 7.7 and 7.8): every overnight room or bed booked,
 * cancelled, moved to other dates or marked as a no-show in the dates, by the time it happened in the property time
 * zone. New bookings are the units created in the dates (an unpaid kiosk hold that was released is left out). The
 * other changes are read from the activity log the front desk actions write, with their reason. Read in a fixed
 * number of queries. See docs/22-reports/05-rooms-on-books.md.
 */
class BookingChangesReport implements HasSections, HasTotals, Report
{
    public function __construct(private ReservationRoomBox $roomBox) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<BookingChangeRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $timeZone = $property->loadMissing('settings')->settings->time_zone ?? config('app.timezone');
        [$start, $end] = $this->bounds($filters, $timeZone);
        $created = $this->createdUnits($property, $start, $end);
        $events = $this->events($property, $start, $end);
        $unitIds = array_values(array_unique([...$created->keys()->all(), ...$events->map(fn (Activity $event): int => (int) $event->properties->get('reservation_unit_id'))->all()]));
        $units = $this->units($property, $unitIds);
        $rooms = $this->roomBox->assignmentsForUnits(array_values(array_map(intval(...), $units->keys()->all())));
        $changes = [];

        foreach ($created as $unitId => $createdAt) {
            $unit = $units->get($unitId);

            if ($unit !== null) {
                $changes[] = $this->newBooking($unit, CarbonImmutable::parse($createdAt, config('app.timezone'))->setTimezone($timeZone));
            }
        }

        foreach ($events as $event) {
            $unit = $units->get((int) $event->properties->get('reservation_unit_id'));

            if ($unit !== null) {
                $changes[] = $this->fromEvent($event, $unit, $event->created_at->toImmutable()->setTimezone($timeZone));
            }
        }

        $order = array_flip(array_map(fn (BookingChangeKind $kind): string => $kind->value, BookingChangeKind::cases()));
        usort($changes, fn (array $a, array $b): int => [$order[$a['kind']->value], $a['at'], $a['key']] <=> [$order[$b['kind']->value], $b['at'], $b['key']]);
        $wanted = $rowKeys === null ? null : array_map('strval', $rowKeys);

        return array_values(array_map(
            fn (array $change): BookingChangeRow => BookingChangeRow::fromChange($change, $units->get($change['unit_id']), $rooms[$change['unit_id']] ?? null),
            array_filter($changes, fn (array $change): bool => $wanted === null || in_array($change['key'], $wanted, true)),
        ));
    }

    /**
     * The net change: the room nights and revenue the changes added, less what they took away.
     *
     * @param  list<BookingChangeRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'kind' => null,
            'changed_at' => null,
            'reservation' => null,
            'guest' => null,
            'room' => null,
            'previous_arrival' => null,
            'previous_departure' => null,
            'arrival' => null,
            'departure' => null,
            'nights' => null,
            'rate' => null,
            'revenue' => null,
            'nights_change' => array_sum(array_map(fn (BookingChangeRow $row): int => $row->nights_change, $rows)),
            'revenue_change' => $this->revenueChange($rows),
            'rate_plan' => null,
            'group' => null,
            'source' => null,
            'booked_by' => null,
            'reason' => null,
        ]);
    }

    /**
     * One section for each kind of change, in the order of `BookingChangeKind`, with its count and net change.
     *
     * @param  list<BookingChangeRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $sections = [];

        foreach (BookingChangeKind::cases() as $kind) {
            $group = array_values(array_filter($rows, fn (BookingChangeRow $row): bool => $row->kind->value === $kind->value));

            if ($group === []) {
                continue;
            }

            $nights = array_sum(array_map(fn (BookingChangeRow $row): int => $row->nights_change, $group));
            $sections[] = new ReportSection(
                title: $kind->pluralLabel(),
                rowKeys: array_map(fn (BookingChangeRow $row): string => $row->key, $group),
                subtotal: trans_choice(':count room|:count rooms', count($group)).' · '.trans_choice(':count room night|:count room nights', $nights, ['count' => ($nights > 0 ? '+' : '').$nights]).' · '.$this->revenueChange($group),
            );
        }

        return $sections;
    }

    /**
     * The dates in the property time zone turned into the stored (app time zone) bounds.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(ReportFilters $filters, string $timeZone): array
    {
        $appTimeZone = (string) config('app.timezone');

        return [
            CarbonImmutable::parse($filters->from, $timeZone)->startOfDay()->setTimezone($appTimeZone),
            CarbonImmutable::parse($filters->to, $timeZone)->addDay()->startOfDay()->setTimezone($appTimeZone),
        ];
    }

    /**
     * The overnight units created in the bounds, with the time each was created. A kiosk hold that was released
     * unpaid never was a booking, so it is left out.
     *
     * @return Collection<int, string>
     */
    private function createdUnits(Property $property, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::Overnight->value)
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $end->toDateTimeString())
            ->whereNotExists(fn (QueryBuilder $holds) => $holds
                ->from('kiosk_walk_in_holds')
                ->whereColumn('kiosk_walk_in_holds.reservation_unit_id', 'reservation_units.id')
                ->whereNotNull('kiosk_walk_in_holds.released_at'))
            ->toBase()
            ->pluck('created_at', 'id');
    }

    /**
     * The cancellations, date changes and no-shows the activity log holds for the property in the bounds.
     *
     * @return Collection<int, Activity>
     */
    private function events(Property $property, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Activity::query()
            ->whereIn('event', array_values(array_filter(array_map(fn (BookingChangeKind $kind): ?string => $kind->activityEvent(), BookingChangeKind::cases()))))
            ->where('properties->property_id', (string) $property->id)
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $end->toDateTimeString())
            ->orderBy('id')
            ->get(['id', 'event', 'properties', 'created_at']);
    }

    /**
     * The overnight units of the property with what the rows show, keyed by id.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, ReservationUnit>
     */
    private function units(Property $property, array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::Overnight->value)
            ->whereIn('id', $unitIds)
            ->with(['reservation.booker', 'reservation.creator', 'reservation.bookingSource', 'roomType', 'ratePlan', 'guests.guest'])
            ->withSum('nights', 'amount')
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array{key: string, unit_id: int, kind: BookingChangeKind, at: CarbonImmutable, arrival: string, departure: string, previous_arrival: ?string, previous_departure: ?string, revenue: string, revenue_change: string, nights_change: int, reason: ?string}
     */
    private function newBooking(ReservationUnit $unit, CarbonImmutable $at): array
    {
        $revenue = $this->bookedAmount($unit);

        return [
            'key' => BookingChangeKind::NewBooking->value.':'.$unit->id,
            'unit_id' => $unit->id,
            'kind' => BookingChangeKind::NewBooking,
            'at' => $at,
            'arrival' => $unit->arrival_date->toDateString(),
            'departure' => $unit->departure_date->toDateString(),
            'previous_arrival' => null,
            'previous_departure' => null,
            'revenue' => $revenue,
            'revenue_change' => $revenue,
            'nights_change' => (int) $unit->arrival_date->diffInDays($unit->departure_date),
            'reason' => null,
        ];
    }

    /**
     * A cancellation or no-show takes the stay's nights and booked price away. An amendment adds the difference
     * between the dates and totals the action logged before and after the change.
     *
     * @return array{key: string, unit_id: int, kind: BookingChangeKind, at: CarbonImmutable, arrival: string, departure: string, previous_arrival: ?string, previous_departure: ?string, revenue: string, revenue_change: string, nights_change: int, reason: ?string}
     */
    private function fromEvent(Activity $event, ReservationUnit $unit, CarbonImmutable $at): array
    {
        $kind = BookingChangeKind::fromActivityEvent((string) $event->event);
        $properties = $event->properties;
        $reason = $properties->get('reason');
        $base = [
            'key' => $kind->value.':'.$event->id,
            'unit_id' => $unit->id,
            'kind' => $kind,
            'at' => $at,
            'reason' => is_string($reason) && $reason !== '' ? $reason : null,
        ];

        if ($kind === BookingChangeKind::Amendment) {
            $arrival = (string) $properties->get('arrival_date');
            $departure = (string) $properties->get('departure_date');
            $previousArrival = (string) $properties->get('previous_arrival_date');
            $previousDeparture = (string) $properties->get('previous_departure_date');
            $total = (string) ($properties->get('total') ?? '0');

            return [
                ...$base,
                'arrival' => $arrival,
                'departure' => $departure,
                'previous_arrival' => $previousArrival,
                'previous_departure' => $previousDeparture,
                'revenue' => $total,
                'revenue_change' => (string) BigDecimal::of($total)->minus((string) ($properties->get('previous_total') ?? '0')),
                'nights_change' => $this->nights($arrival, $departure) - $this->nights($previousArrival, $previousDeparture),
            ];
        }

        $revenue = $this->bookedAmount($unit);

        return [
            ...$base,
            'arrival' => $unit->arrival_date->toDateString(),
            'departure' => $unit->departure_date->toDateString(),
            'previous_arrival' => null,
            'previous_departure' => null,
            'revenue' => $revenue,
            'revenue_change' => (string) BigDecimal::of($revenue)->negated(),
            'nights_change' => -(int) $unit->arrival_date->diffInDays($unit->departure_date),
        ];
    }

    private function bookedAmount(ReservationUnit $unit): string
    {
        return (string) BigDecimal::of((string) ($unit->getAttribute('nights_sum_amount') ?? '0'));
    }

    private function nights(string $arrival, string $departure): int
    {
        return (int) CarbonImmutable::parse($arrival)->diffInDays(CarbonImmutable::parse($departure));
    }

    /**
     * @param  list<BookingChangeRow>  $rows
     */
    private function revenueChange(array $rows): string
    {
        return (string) array_reduce($rows, fn (BigDecimal $sum, BookingChangeRow $row): BigDecimal => $sum->plus($row->revenue_change), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }
}
