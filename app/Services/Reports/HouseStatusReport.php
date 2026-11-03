<?php

namespace App\Services\Reports;

use App\Data\Reports\HousekeepingItemRow;
use App\Data\Reports\HouseStatusRow;
use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\RoomBlockReason;
use App\Enums\StayType;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\UnitBoatTrip;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Every room that needs housekeeping on one date, as Sand Bay's House status sheet: rooms leaving and arriving by boat
 * time (or by expected time without the Boats feature), charter and own boat, boats not set, room moves, stay-overs
 * (OCC) and rooms blocked for owner use (Management). Each tile carries the pax, the room label, the room, the
 * housekeeping items (EXTRA BED), TL for a tour leader (a guest tagged "TL" or "Tour leader") and the room remark. See
 * docs/22-reports/03-house-status-and-items.md#house-status.
 */
class HouseStatusReport implements HasPrintView, HasSections, HasTotals, Report
{
    private const array TOUR_LEADER_TAGS = ['tl', 'tour leader'];

    public function __construct(
        private ReservationRoomBox $roomBox,
        private HousekeepingItemsReport $housekeepingItems,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys
     * @return list<HouseStatusRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $date = CarbonImmutable::parse($filters->from)->startOfDay();
        $today = $date->toDateString();
        $yesterday = $date->subDay()->toDateString();
        $boatsOn = Feature::for($property)->active(Boats::class);

        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->where('stay_type', StayType::Overnight->value)
            ->whereDate('arrival_date', '<=', $today)
            ->whereDate('departure_date', '>=', $today)
            ->with(['roomType', 'guests.guest.tags'])
            ->orderBy('id')
            ->get();
        $unitIds = array_values(array_map(intval(...), $units->modelKeys()));
        $roomsToday = $this->roomBox->assignmentsForUnitsOn($unitIds, $today);
        $roomsYesterday = $this->roomBox->assignmentsForUnitsOn($unitIds, $yesterday);
        $lastNight = $this->roomBox->lastNightAssignmentsForUnits($unitIds);
        $rooms = Room::query()->where('property_id', $property->id)->with('roomType')->get();
        $roomByNumber = $rooms->whereNull('archived_at')->keyBy('number');
        $remarks = RoomStatus::query()->whereIn('room_id', $rooms->modelKeys())->whereNotNull('remark')->pluck('remark', 'room_id');
        $trips = $boatsOn ? UnitBoatTrip::query()->whereIn('reservation_unit_id', $unitIds)->with('boatDeparture')->get()->groupBy('reservation_unit_id') : collect();
        $items = $this->itemsByUnit($property, $filters);
        $rows = [];

        foreach ($units as $unit) {
            $flags = [...($items[$unit->id] ?? []), ...($this->isTourLeader($unit) ? ['TL'] : [])];
            $pax = $unit->adults + $unit->children;
            $tile = function (string $kind, ?string $number, array $group) use ($unit, $pax, $flags, $roomByNumber, $remarks): HouseStatusRow {
                $room = $number === null ? null : $roomByNumber->get($number);

                return new HouseStatusRow(
                    key: $kind.':'.$unit->id,
                    group: $group['label'],
                    room: $number ?? $unit->roomType->name,
                    label: $this->label($room, $unit->roomType),
                    pax: $pax,
                    flags: $flags,
                    remark: $room === null ? null : $remarks->get($room->id),
                    kind: $kind,
                    group_key: $group['key'],
                    group_order: $group['order'],
                    checked_out: $kind === 'out' && $unit->status === UnitStatus::CheckedOut,
                    warning: $group['warning'] ?? false,
                );
            };

            if ($unit->departure_date->toDateString() === $today) {
                $rows[] = $tile('out', $lastNight[$unit->id] ?? null, $this->movementGroup($unit, BoatDirection::Out, $boatsOn, $trips->get($unit->id)));

                continue;
            }

            if ($unit->arrival_date->toDateString() === $today) {
                $rows[] = $tile('in', $roomsToday[$unit->id] ?? null, $this->movementGroup($unit, BoatDirection::In, $boatsOn, $trips->get($unit->id)));

                continue;
            }

            $before = $roomsYesterday[$unit->id] ?? null;
            $now = $roomsToday[$unit->id] ?? null;

            if ($before !== null && $now !== null && $before !== $now) {
                $group = ['key' => 'move', 'label' => __('Room move'), 'order' => [3, '', '']];
                $rows[] = $tile('move_out', $before, $group);
                $rows[] = $tile('move_in', $now, $group);

                continue;
            }

            $rows[] = $tile('occ', $now, ['key' => 'occ', 'label' => __('OCC (stay-over)'), 'order' => [4, '', '']]);
        }

        foreach ($this->managementBlocks($property, $today) as $block) {
            $rows[] = new HouseStatusRow(
                key: 'management:'.$block->id,
                group: __('Management'),
                room: $block->room->number,
                label: $this->label($block->room, $block->room->roomType),
                pax: 0,
                flags: [],
                remark: $block->notes ?? $remarks->get($block->room_id),
                kind: 'management',
                group_key: 'management',
                group_order: [5, '', ''],
            );
        }

        if ($rowKeys !== null) {
            $keys = array_map(strval(...), $rowKeys);
            $rows = array_values(array_filter($rows, fn (HouseStatusRow $row): bool => in_array($row->key, $keys, true)));
        }

        // Every group order has three parts (rank, time, direction): PHP compares arrays of different lengths by count.
        usort($rows, fn (HouseStatusRow $first, HouseStatusRow $second): int => [$first->group_order, $first->room] <=> [$second->group_order, $second->room]);

        return $rows;
    }

    /**
     * One section per group, in the order the rows come, each with its pax and rooms.
     *
     * @param  list<HouseStatusRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->group_key][] = $row;
        }

        return array_values(array_map(fn (array $group): ReportSection => new ReportSection(
            title: $group[0]->group,
            rowKeys: array_map(fn (HouseStatusRow $row): string => $row->key, $group),
            subtotal: $this->groupTotal($group),
            tone: $group[0]->warning ? Tone::Warning : null,
        ), $groups));
    }

    /**
     * @param  list<HouseStatusRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'group' => null,
            'room' => null,
            'label' => null,
            'pax' => array_sum(array_map(fn (HouseStatusRow $row): int => $row->pax, array_filter($rows, fn (HouseStatusRow $row): bool => $row->kind !== 'move_out'))),
            'flags' => null,
            'remark' => null,
        ]);
    }

    public function printView(): string
    {
        return 'pdf.house-status';
    }

    /**
     * The groups with their tiles, and the housekeeping items of the date for the Extra amenities table.
     *
     * @param  list<HouseStatusRow>  $rows
     * @return array<string, mixed>
     */
    public function printData(Property $property, ReportFilters $filters, array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->group_key] ??= ['title' => $row->group, 'kind' => $row->kind, 'warning' => $row->warning, 'tiles' => []];
            $groups[$row->group_key]['tiles'][] = $row;
        }

        return [
            'workingDate' => CarbonImmutable::parse($filters->from)->format('d/m/Y'),
            'groups' => array_values(array_map(fn (array $group): array => [...$group, 'total' => $this->groupTotal($group['tiles'])], $groups)),
            'items' => array_values(array_filter(
                $this->housekeepingItems->rows($property, $filters),
                fn (HousekeepingItemRow $item): bool => $item->action !== __('Remove'),
            )),
        ];
    }

    /**
     * "12 pax · 4 rooms". A room move counts its new room only.
     *
     * @param  list<HouseStatusRow>  $rows
     */
    private function groupTotal(array $rows): string
    {
        $counted = array_filter($rows, fn (HouseStatusRow $row): bool => $row->kind !== 'move_out');
        $pax = array_sum(array_map(fn (HouseStatusRow $row): int => $row->pax, $counted));

        return trans_choice(':count pax|:count pax', $pax).' · '.trans_choice(':count room|:count rooms', count($counted));
    }

    /**
     * The group of a room that leaves or arrives on the date: its scheduled boat by time, a charter or own boat, or
     * Not set. Without the Boats feature, the unit's expected time.
     *
     * @param  Collection<int, UnitBoatTrip>|null  $trips
     * @return array{key: string, label: string, order: array{int, string, string}, warning?: bool}
     */
    private function movementGroup(ReservationUnit $unit, BoatDirection $direction, bool $boatsOn, ?Collection $trips): array
    {
        $time = substr($direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5);
        $word = $direction === BoatDirection::In ? __('In') : __('Out');

        if (! $boatsOn) {
            return ['key' => $direction->value.':'.$time, 'label' => $word.' · '.$time, 'order' => [0, $time, $direction->value]];
        }

        $trip = $trips?->firstWhere('direction', $direction);

        if ($trip === null) {
            return ['key' => 'not-set', 'label' => __('Boat not set'), 'order' => [2, '', ''], 'warning' => true];
        }

        if ($trip->mode === BoatMode::Scheduled && $trip->boatDeparture !== null) {
            $leaves = substr($trip->boatDeparture->leaves_at, 0, 5);

            return ['key' => 'departure:'.$trip->boatDeparture->id, 'label' => $direction->label().' · '.$leaves, 'order' => [0, $leaves, $direction->value]];
        }

        $mode = $trip->mode === BoatMode::Charter ? __('Charter') : __('Own boat');

        return ['key' => $trip->mode->value.':'.$direction->value, 'label' => $mode.' · '.mb_strtolower($word), 'order' => [1, $direction->value, $trip->mode->value]];
    }

    private function label(?Room $room, RoomType $roomType): string
    {
        return $room?->print_label ?? $roomType->print_label ?? $this->initials($roomType->name);
    }

    /**
     * The first letter of each word of the room type name, upper case, with "Room" dropped and `+` kept, as the
     * room type short label in the browser ("Deluxe Super King" gives DSK).
     */
    private function initials(string $name): string
    {
        $words = array_filter(preg_split('/[\s-]+/', $name) ?: [], fn (string $word): bool => $word !== '' && mb_strtolower($word) !== 'room');

        return implode('', array_map(fn (string $word): string => $word === '+' ? '+' : mb_strtoupper(mb_substr($word, 0, 1)), $words));
    }

    private function isTourLeader(ReservationUnit $unit): bool
    {
        return $unit->guests->contains(fn ($unitGuest): bool => $unitGuest->guest?->tags->contains(
            fn ($tag): bool => in_array(mb_strtolower($tag->name), self::TOUR_LEADER_TAGS, true),
        ) ?? false);
    }

    /**
     * The housekeeping items in each room on the date, upper case (EXTRA BED), by unit id.
     *
     * @return array<int, list<string>>
     */
    private function itemsByUnit(Property $property, ReportFilters $filters): array
    {
        $items = [];

        foreach ($this->housekeepingItems->rows($property, $filters) as $item) {
            if ($item->action === __('Remove')) {
                continue;
            }

            $items[(int) explode(':', $item->key)[0]][] = mb_strtoupper($item->item);
        }

        return $items;
    }

    /**
     * The rooms blocked for owner use on the date.
     *
     * @return Collection<int, RoomBlock>
     */
    private function managementBlocks(Property $property, string $date): Collection
    {
        return RoomBlock::query()
            ->where('property_id', $property->id)
            ->where('reason', RoomBlockReason::OwnerUse->value)
            ->whereNull('completed_at')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->with('room.roomType')
            ->get();
    }
}
