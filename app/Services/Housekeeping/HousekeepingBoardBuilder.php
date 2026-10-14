<?php

namespace App\Services\Housekeeping;

use App\Data\Housekeeping\HousekeepingBedBlockData;
use App\Data\Housekeeping\HousekeepingOutOfServiceData;
use App\Data\Housekeeping\HousekeepingRoomData;
use App\Enums\HousekeepingReason;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the housekeeping board: one row for each active room on a date, with its status, block and the units that
 * arrive, depart or stay. It never writes. See docs/19-housekeeping/01-room-status-board.md.
 */
class HousekeepingBoardBuilder
{
    /**
     * Rows are ordered by `due`: departures by `departure_time`, then arrivals by `arrival_time`, then the other
     * rooms by room order, and rooms out of service last.
     *
     * @return list<HousekeepingRoomData>
     */
    public function rows(Property $property, CarbonImmutable $date, bool $canUpdate, bool $canInspect, bool $canBlock): array
    {
        $rooms = Room::query()
            ->where('property_id', $property->id)
            ->active()->ordered()
            ->with(['roomType', 'roomGroup', 'status.assignee'])
            ->get();

        $blocks = RoomBlock::query()
            ->where('property_id', $property->id)
            ->open()->overlapping($date, $date)
            ->with('bed')
            ->orderBy('ends_on')
            ->get()
            ->groupBy('room_id');

        $stays = $this->staysByRoom($property, $date);
        $dateString = $date->toDateString();

        $rows = $rooms->map(function (Room $room) use ($blocks, $stays, $canUpdate, $canInspect, $canBlock, $dateString): array {
            $roomBlocks = $blocks->get($room->id, collect());
            $wholeRoom = $roomBlocks->first(fn (RoomBlock $block): bool => $block->bed_id === null);
            $bedBlocks = $roomBlocks->filter(fn (RoomBlock $block): bool => $block->bed_id !== null)
                ->map(fn (RoomBlock $block): HousekeepingBedBlockData => HousekeepingBedBlockData::fromModel($block))
                ->values()->all();
            $stay = $stays->get($room->id, $this->emptyStay());

            $row = HousekeepingRoomData::forRoom(
                room: $room,
                outOfService: $wholeRoom === null ? null : HousekeepingOutOfServiceData::fromModel($wholeRoom),
                bedBlocks: $bedBlocks,
                stay: $stay['view'],
                reason: $stay['reason'],
                canUpdate: $canUpdate,
                canInspect: $canInspect,
                canBlock: $canBlock,
                date: $dateString,
            );

            return ['row' => $row, 'due' => $wholeRoom !== null ? '3' : $stay['due']];
        })->all();

        usort($rows, fn (array $a, array $b): int => $a['due'] <=> $b['due']);

        return array_map(fn (array $item): HousekeepingRoomData => $item['row'], $rows);
    }

    /**
     * Group the units that touch the date by the room they use that day.
     *
     * @return Collection<int, array{view: array{guest: ?string, arrivalDate: ?string, arrivalTime: ?string, departureDate: ?string, departureTime: ?string, isStayOver: bool}, reason: ?HousekeepingReason, due: string}>
     */
    private function staysByRoom(Property $property, CarbonImmutable $date): Collection
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('stay_type', StayType::Overnight->value)
            ->whereIn('status', [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value])
            ->whereDate('arrival_date', '<=', $date)
            ->whereDate('departure_date', '>=', $date)
            ->with([
                'nights' => fn ($query) => $query->whereDate('date', '>=', $date->subDay())->whereDate('date', '<=', $date),
                'nights.bed',
                'guests' => fn ($query) => $query->where('is_primary', true),
                'guests.guest',
            ])
            ->get();

        $byRoom = [];

        foreach ($units as $unit) {
            $departing = $unit->departure_date->toDateString() === $date->toDateString();

            if ($departing && $unit->status === UnitStatus::Confirmed) {
                continue;
            }

            $night = $unit->nights->first(fn ($candidate): bool => $candidate->date->toDateString() === ($departing ? $date->subDay() : $date)->toDateString());
            $roomId = $night->room_id ?? $night?->bed?->room_id;

            if ($roomId === null) {
                continue;
            }

            $byRoom[$roomId][] = ['unit' => $unit, 'departing' => $departing];
        }

        return collect($byRoom)->map(fn (array $entries): array => $this->summarize($entries, $date));
    }

    /**
     * @param  list<array{unit: ReservationUnit, departing: bool}>  $entries
     * @return array{view: array{guest: ?string, arrivalDate: ?string, arrivalTime: ?string, departureDate: ?string, departureTime: ?string, isStayOver: bool}, reason: ?HousekeepingReason, due: string}
     */
    private function summarize(array $entries, CarbonImmutable $date): array
    {
        $departing = collect($entries)->first(fn (array $entry): bool => $entry['departing'])['unit'] ?? null;
        $present = collect($entries)->first(fn (array $entry): bool => ! $entry['departing'] && $entry['unit']->status === UnitStatus::CheckedIn && $entry['unit']->arrival_date->toDateString() < $date->toDateString())['unit'] ?? null;
        $arriving = collect($entries)->first(fn (array $entry): bool => ! $entry['departing'] && ! ($entry['unit']->status === UnitStatus::CheckedIn && $entry['unit']->arrival_date->toDateString() < $date->toDateString()))['unit'] ?? null;

        $guestUnit = $departing ?? $present ?? $arriving;
        $inHouse = ($departing !== null && $departing->status === UnitStatus::CheckedIn) || $present !== null;

        $reason = match (true) {
            $departing !== null && $departing->status === UnitStatus::CheckedOut => HousekeepingReason::Departed,
            $inHouse => HousekeepingReason::StayOver,
            default => null,
        };

        $due = match (true) {
            $departing !== null => '0'.substr($departing->departure_time, 0, 5),
            $arriving !== null => '1'.substr($arriving->arrival_time, 0, 5),
            default => '2',
        };

        return [
            'view' => [
                'guest' => $guestUnit?->guests->first()?->guest?->full_name,
                'arrivalDate' => $arriving?->arrival_date->toDateString(),
                'arrivalTime' => $arriving === null ? null : substr($arriving->arrival_time, 0, 5),
                'departureDate' => $departing?->departure_date->toDateString(),
                'departureTime' => $departing === null ? null : substr($departing->departure_time, 0, 5),
                'isStayOver' => $present !== null,
            ],
            'reason' => $reason,
            'due' => $due,
        ];
    }

    /**
     * @return array{view: array{guest: ?string, arrivalDate: ?string, arrivalTime: ?string, departureDate: ?string, departureTime: ?string, isStayOver: bool}, reason: ?HousekeepingReason, due: string}
     */
    private function emptyStay(): array
    {
        return [
            'view' => ['guest' => null, 'arrivalDate' => null, 'arrivalTime' => null, 'departureDate' => null, 'departureTime' => null, 'isStayOver' => false],
            'reason' => null,
            'due' => '2',
        ];
    }
}
