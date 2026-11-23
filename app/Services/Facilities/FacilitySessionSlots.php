<?php

namespace App\Services\Facilities;

use App\Enums\FacilitySessionLength;

/**
 * Splits a range into back-to-back sessions of one length, for Add sessions. A time left over at the end that is
 * shorter than the length is dropped, so 08:00–21:00 in 2 hours ends at 20:00. Whole gives the range as one session.
 * Times are `HH:MM` on one day. See docs/33-facilities/02-facility-settings.md#sessions.
 */
class FacilitySessionSlots
{
    /**
     * @return list<array{starts_at: string, ends_at: string}>
     */
    public function between(string $startsAt, string $endsAt, FacilitySessionLength $length): array
    {
        $from = self::minutes($startsAt);
        $to = self::minutes($endsAt);
        $step = $length->minutes() ?? ($to - $from);

        if ($step <= 0 || $to <= $from) {
            return [];
        }

        $slots = [];

        for ($start = $from; $start + $step <= $to; $start += $step) {
            $slots[] = ['starts_at' => self::time($start), 'ends_at' => self::time($start + $step)];
        }

        return $slots;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private static function time(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
