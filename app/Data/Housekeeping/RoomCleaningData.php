<?php

namespace App\Data\Housekeeping;

use App\Models\RoomCleaning;
use Spatie\LaravelData\Data;

/**
 * One cleaning of a room, with its IN and OUT time in the property's time zone (`H:i`). Expects `housekeeper` loaded.
 */
class RoomCleaningData extends Data
{
    public function __construct(
        public int $id,
        public int $housekeeperId,
        public string $housekeeperName,
        public string $date,
        public string $inTime,
        public ?string $outTime,
        public ?int $minutes,
    ) {}

    public static function fromModel(RoomCleaning $cleaning, string $timeZone): self
    {
        return new self(
            id: $cleaning->id,
            housekeeperId: $cleaning->housekeeper_id,
            housekeeperName: $cleaning->housekeeper->name,
            date: $cleaning->date->toDateString(),
            inTime: $cleaning->started_at->setTimezone($timeZone)->format('H:i'),
            outTime: $cleaning->finished_at?->setTimezone($timeZone)->format('H:i'),
            minutes: $cleaning->finished_at === null ? null : (int) $cleaning->started_at->diffInMinutes($cleaning->finished_at),
        );
    }
}
