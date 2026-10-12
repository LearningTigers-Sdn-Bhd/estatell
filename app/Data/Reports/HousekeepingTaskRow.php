<?php

namespace App\Data\Reports;

use App\Data\Housekeeping\HousekeepingRoomData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the housekeeping work sheet, one for each active room. The property names are the column keys of
 * `ReportKey::HousekeepingTasks`. `key` is the room id and `due` is the place of the room in the board order, so the
 * default sort `due` keeps departures first. `arrival` and `departure` hold `Y-m-d H:i` (the date alone without a
 * time), so a sort puts them in time order; the cells format them.
 */
class HousekeepingTaskRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public int $due,
        public string $room,
        public ?string $room_group,
        public string $unit,
        public string $room_type,
        public string $status,
        public ?string $assigned,
        public ?string $guest,
        public ?string $arrival,
        public ?string $arrival_date,
        public ?string $arrival_time,
        public ?string $departure,
        public ?string $departure_date,
        public ?string $departure_time,
        public string $flags,
        public bool $priority,
        public bool $do_not_disturb,
        public bool $needs_maintenance,
        public string $remarks,
        public ?string $remark,
        public ?string $system_reason,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'room' => $this->room,
            'room_group' => $this->room_group ?? '',
            'unit' => $this->unit,
            'room_type' => $this->room_type,
            'status' => $this->status,
            'assigned' => $this->assigned ?? '',
            'guest' => $this->guest ?? '',
            'arrival' => $this->moment($this->arrival_date, $this->arrival_time),
            'arrival_date' => $this->arrival_date === null ? '' : CarbonImmutable::parse($this->arrival_date)->format('j M Y'),
            'arrival_time' => $this->arrival_time ?? '',
            'departure' => $this->moment($this->departure_date, $this->departure_time),
            'departure_date' => $this->departure_date === null ? '' : CarbonImmutable::parse($this->departure_date)->format('j M Y'),
            'departure_time' => $this->departure_time ?? '',
            'flags' => $this->flags,
            'priority' => $this->priority ? __('Yes') : '',
            'do_not_disturb' => $this->do_not_disturb ? __('Yes') : '',
            'needs_maintenance' => $this->needs_maintenance ? __('Yes') : '',
            'remarks' => $this->remarks,
            'remark' => $this->remark ?? '',
            'system_reason' => $this->system_reason ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            ...$this->cells(),
            'arrival_date' => $this->arrival_date ?? '',
            'departure_date' => $this->departure_date ?? '',
        ];
    }

    /**
     * @param  int  $due  The place of the room in the board order, from 1.
     */
    public static function fromRoom(HousekeepingRoomData $room, int $due): self
    {
        $flags = array_filter([
            $room->isPriority ? __('Priority') : null,
            $room->doNotDisturb ? __('DND') : null,
            $room->needsMaintenance ? __('Maint.') : null,
        ]);

        return new self(
            key: $room->id,
            due: $due,
            room: $room->number,
            room_group: $room->roomGroupName,
            unit: "{$room->roomTypeName} / {$room->number}",
            room_type: $room->roomTypeName,
            status: $room->status->label,
            assigned: $room->assignedName,
            guest: $room->guest,
            arrival: $room->arrivalDate === null ? null : trim("{$room->arrivalDate} {$room->arrivalTime}"),
            arrival_date: $room->arrivalDate,
            arrival_time: $room->arrivalTime,
            departure: $room->departureDate === null ? null : trim("{$room->departureDate} {$room->departureTime}"),
            departure_date: $room->departureDate,
            departure_time: $room->departureTime,
            flags: implode(' · ', $flags),
            priority: $room->isPriority,
            do_not_disturb: $room->doNotDisturb,
            needs_maintenance: $room->needsMaintenance,
            remarks: implode(' · ', array_filter([$room->remark, $room->reason], fn (?string $part): bool => $part !== null && $part !== '')),
            remark: $room->remark,
            system_reason: $room->reason,
        );
    }

    private function moment(?string $date, ?string $time): string
    {
        if ($date === null) {
            return '';
        }

        $day = CarbonImmutable::parse($date)->format('j M');

        return $time === null ? $day : "{$day} · {$time}";
    }
}
