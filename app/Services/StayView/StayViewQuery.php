<?php

namespace App\Services\StayView;

use App\Data\StayView\BlockData;
use App\Data\StayView\DateRateData;
use App\Data\StayView\DayUseData;
use App\Data\StayView\RatePriceRowData;
use App\Data\StayView\RoomRowData;
use App\Data\StayView\StayData;
use App\Data\StayView\StaySegmentData;
use App\Data\StayView\StayViewWindowData;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use App\Services\Folio\FolioBalance;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Rates\RateCalendarService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads what the Timeline View and Room View show for one property: rows, stays with their segments, and blocks.
 * It never writes and runs a fixed number of queries, however many stays the window holds. Room View reads the same
 * data with `$from` equal to `$to`. See docs/18-front-desk/03-stay-view.md.
 */
class StayViewQuery
{
    public function __construct(
        private CurrentBusinessDate $businessDate,
        private PropertyToday $propertyToday,
        private FolioBalance $folioBalance,
        private RateCalendarService $rateCalendar,
    ) {}

    public function window(Property $property, CarbonImmutable $from, CarbonImmutable $to): StayViewWindowData
    {
        $today = $this->propertyToday->date($property);

        return StayViewWindowData::forDates($from, $to, $today, $this->businessDate->date($property) ?? $today);
    }

    /**
     * One row for each active room, or for each active bed of a room type that sells beds. Rows follow room order.
     *
     * @return list<RoomRowData>
     */
    public function rows(Property $property, CarbonImmutable $date): array
    {
        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->active()->ordered()
            ->with(['roomType', 'roomGroup', 'status', 'beds' => fn ($query) => $query->active()->ordered()])
            ->get();

        $rows = [];

        foreach ($rooms as $room) {
            if ($room->roomType->sell_unit === SellUnit::Bed) {
                foreach ($room->beds as $bed) {
                    $rows[] = RoomRowData::forRoom($room, $bed, $date);
                }

                continue;
            }

            $rows[] = RoomRowData::forRoom($room, null, $date);
        }

        return $rows;
    }

    /**
     * Overnight units that hold a night in the window. Cancelled and no-show units are left out. A night with no
     * room or bed yet is in the unit's `unassigned-{roomTypeId}` segment. Day use is read by `dayUses()`.
     *
     * @return list<StayData>
     */
    public function stays(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::Overnight->value)
            ->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value])
            ->whereDate('arrival_date', '<=', $to)
            ->whereDate('departure_date', '>', $from)
            ->with([
                'reservation' => fn ($query) => $query
                    ->select('id', 'reference', 'group_name', 'booking_source_id')
                    ->withCount(['units as live_units_count' => fn ($units) => $units->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))]),
                'reservation.bookingSource:id,name,type,badge_icon,badge_letters,badge_color',
                'nights' => fn ($query) => $query->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date'),
                'guests' => fn ($query) => $query->where('is_primary', true),
                'guests.guest:id,full_name',
            ])
            ->orderBy('arrival_date')->orderBy('id')
            ->get();

        $balances = $this->folioBalance->forReservations(array_values($units->pluck('reservation_id')->unique()->all()));

        return array_values($units
            ->map(fn (ReservationUnit $unit): StayData => StayData::fromUnit(
                $unit,
                $this->segments($unit->nights, $unit->room_type_id),
                BigDecimal::of($balances[$unit->reservation_id] ?? '0.00')->isGreaterThan(0),
            ))
            ->all());
    }

    /**
     * Live day use blocks with a room on a date in the window. A released block (cancelled or no-show) is left out.
     *
     * @return list<DayUseData>
     */
    public function dayUses(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blocks = UnitDayUse::query()
            ->whereNull('released_at')
            ->whereNotNull('room_id')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereHas('reservationUnit', fn ($units) => $units
                ->where('property_id', $property->id)
                ->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value]))
            ->with([
                'reservationUnit.reservation:id,reference,booking_source_id',
                'reservationUnit.reservation.bookingSource:id,name,type,badge_icon,badge_letters,badge_color',
                'reservationUnit.guests' => fn ($query) => $query->where('is_primary', true),
                'reservationUnit.guests.guest:id,full_name',
            ])
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')
            ->get();

        return array_values($blocks->map(fn (UnitDayUse $block): DayUseData => DayUseData::fromModel($block))->all());
    }

    /**
     * Open blocks that share a night with the window.
     *
     * @return list<BlockData>
     */
    public function blocks(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blocks = RoomBlock::query()
            ->where('property_id', $property->id)
            ->open()->overlapping($from, $to)
            ->orderBy('starts_on')->orderBy('id')
            ->get();

        return array_values($blocks->map(fn (RoomBlock $block): BlockData => BlockData::fromModel($block))->all());
    }

    /**
     * The nightly price of each rate plan for each room type, for the rate row. It reads the rate calendar, so a
     * derived plan shows the same price as there. Package and day use plans are left out: their price is not for one
     * night.
     *
     * @return list<RatePriceRowData>
     */
    public function rates(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        foreach ($this->rateCalendar->prices($property, $from, $to, $property->settings->currency->getCurrencyCode()) as $group) {
            foreach ($group['plans'] as $plan) {
                if ($plan->pricingBasis === PricingBasis::Package || $plan->pricingBasis === PricingBasis::DayUse) {
                    continue;
                }

                $rows[] = new RatePriceRowData(
                    roomTypeId: $group['roomType']->id,
                    roomTypeName: $group['roomType']->name,
                    ratePlanId: $plan->id,
                    ratePlanName: $plan->name,
                    days: array_map(DateRateData::fromCell(...), $plan->cells),
                );
            }
        }

        return $rows;
    }

    /**
     * Join consecutive nights in the same room or bed. A night with neither is in the `unassigned-{roomTypeId}` row,
     * which the browser draws as the Unassigned lane. See docs/18-front-desk/07-timeline-editing.md.
     *
     * @param  Collection<int, UnitNight>  $nights  Sorted by date.
     * @return list<StaySegmentData>
     */
    private function segments(Collection $nights, int $roomTypeId): array
    {
        /** @var list<array{rowKey: string, firstNight: string, lastNight: string}> $runs */
        $runs = [];
        $last = null;

        foreach ($nights as $night) {
            $rowKey = match (true) {
                $night->bed_id !== null => 'bed-'.$night->bed_id,
                $night->room_id !== null => 'room-'.$night->room_id,
                default => 'unassigned-'.$roomTypeId,
            };

            $date = $night->date->toDateString();
            $continues = $last !== null && $last['rowKey'] === $rowKey && $last['date']->addDay()->toDateString() === $date;

            if ($continues) {
                $runs[array_key_last($runs)]['lastNight'] = $date;
            } else {
                $runs[] = ['rowKey' => $rowKey, 'firstNight' => $date, 'lastNight' => $date];
            }

            $last = ['rowKey' => $rowKey, 'date' => $night->date];
        }

        return array_map(fn (array $run): StaySegmentData => new StaySegmentData($run['rowKey'], $run['firstNight'], $run['lastNight']), $runs);
    }
}
