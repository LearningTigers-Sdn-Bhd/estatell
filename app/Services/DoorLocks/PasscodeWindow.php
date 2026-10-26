<?php

namespace App\Services\DoorLocks;

use App\Enums\PasscodeKind;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

/**
 * Works out when a stay's PIN starts and ends: from the check-in moment to the unit's departure, in the property time zone.
 * A generated PIN is accurate to the hour, so it starts on the hour before and ends on the hour after.
 */
class PasscodeWindow
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} start and end
     */
    public function forUnit(ReservationUnit $unit, PasscodeKind $kind, string $timeZone): array
    {
        $startsAt = ($unit->checked_in_at ?? now())->toImmutable()->max(now()->subMinute());
        $endsAt = CarbonImmutable::parse($unit->departure_date->toDateString().' '.$unit->departure_time, $timeZone)->utc();

        return $this->fit($startsAt, $endsAt, $kind);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function fit(CarbonImmutable $startsAt, CarbonImmutable $endsAt, PasscodeKind $kind): array
    {
        if ($kind === PasscodeKind::Generated) {
            $startsAt = $startsAt->startOfHour();
            $endsAt = $endsAt->minute > 0 || $endsAt->second > 0 ? $endsAt->addHour()->startOfHour() : $endsAt;
        }

        return [$startsAt, $endsAt];
    }
}
