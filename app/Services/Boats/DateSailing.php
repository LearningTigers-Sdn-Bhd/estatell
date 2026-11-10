<?php

namespace App\Services\Boats;

use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One sailing of a departure on a date, as the pages read it: stored (`$sailing` set) or the main sailing built in memory
 * from the timetable (`$sailing` null). Times are `HH:MM`, the sailing's own or else the timetable's. `$seats` is the
 * limit of this sailing, null for none. See docs/24-boats/04-sailings.md.
 */
final class DateSailing
{
    /**
     * @param  Collection<int, BoatCrewMember>  $crew  The one in charge (`pivot->in_charge`) first, then the assistants.
     */
    public function __construct(
        public readonly BoatDeparture $departure,
        public readonly CarbonImmutable $date,
        public readonly ?BoatSailing $sailing,
        public readonly bool $isMain,
        public readonly ?Boat $boat,
        public readonly Collection $crew,
        public readonly string $leavesAt,
        public readonly string $arrivesAt,
        public readonly bool $timeChanged,
        public readonly ?int $seats,
        public readonly bool $cancelled = false,
    ) {}

    /** A key that is the same for the stored and the in-memory form of the main sailing is not needed: one exists at a time. */
    public function key(): string
    {
        return $this->sailing === null ? 'main-'.$this->departure->id : 'sailing-'.$this->sailing->id;
    }

    /** The id the sailings of a time are ordered by after the time: the row id, else the departure id. */
    public function sortId(): int
    {
        return $this->sailing?->id ?? $this->departure->id;
    }

    /** The time at the property: the arrival for Boat in, the departure for Boat out. */
    public function propertyTime(): string
    {
        return $this->departure->direction->value === 'in' ? $this->arrivesAt : $this->leavesAt;
    }

    public function inCharge(): ?BoatCrewMember
    {
        return $this->crew->first(fn (BoatCrewMember $member): bool => (bool) $member->pivot->in_charge);
    }

    /** @return Collection<int, BoatCrewMember> */
    public function assistants(): Collection
    {
        return $this->crew->reject(fn (BoatCrewMember $member): bool => (bool) $member->pivot->in_charge)->values();
    }
}
