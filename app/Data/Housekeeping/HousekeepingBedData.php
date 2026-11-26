<?php

namespace App\Data\Housekeeping;

use App\Data\Shared\BadgeData;
use App\Enums\HousekeepingStatus;
use App\Enums\HousekeepingStay;
use App\Enums\Tone;
use App\Models\Bed;
use Spatie\LaravelData\Data;

/**
 * One bed of a dorm row on the housekeeping board: its status, the moves the viewer may make (none while the bed or
 * the room is blocked), and who is in it today. See docs/19-housekeeping/04-dorm-beds.md.
 */
class HousekeepingBedData extends Data
{
    /**
     * @param  list<BadgeData>  $moves
     */
    public function __construct(
        public int $id,
        public string $label,
        public BadgeData $status,
        public array $moves,
        public bool $blocked,
        public ?BadgeData $stay,
        public ?string $guest,
        public ?string $guestRemark,
    ) {}

    /**
     * Expects the bed's `status` loaded.
     *
     * @param  array{stay: ?HousekeepingStay, guest: ?string, guestRemark: ?string}|null  $occupant
     */
    public static function forBed(Bed $bed, bool $blocked, ?array $occupant, bool $canUpdate, bool $canInspect): self
    {
        $status = $bed->status->status ?? HousekeepingStatus::Clean;
        $stay = $occupant['stay'] ?? null;

        return new self(
            id: $bed->id,
            label: $bed->label,
            status: $blocked ? new BadgeData('out_of_service', __('Blocked'), Tone::Danger) : BadgeData::fromHousekeepingStatus($status),
            moves: $blocked || ! $canUpdate ? [] : array_map(fn (HousekeepingStatus $move): BadgeData => BadgeData::fromHousekeepingStatus($move), $status->movesFor($canInspect)),
            blocked: $blocked,
            stay: $stay === null ? null : new BadgeData($stay->value, $stay->label(), $stay->tone()),
            guest: $occupant['guest'] ?? null,
            guestRemark: $occupant['guestRemark'] ?? null,
        );
    }
}
