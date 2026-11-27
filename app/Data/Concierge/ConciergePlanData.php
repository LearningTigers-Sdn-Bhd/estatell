<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * One thing booked for the guest's stay, for the "Your plans" list of the Concierge page: a road transfer or an
 * activity. `kind` is `transfer` or `activity`, `date` is `Y-m-d` and `time` is "10:30" or "06:00–08:00". `title`
 * says what it is ("Pick-up at Sandakan Airport", "River Safari"), and `detail` the rest ("Flight AK 6231 · Shared
 * van", "2 people"). It never names a guest. See docs/30-concierge/01-overview.md#your-plans.
 */
class ConciergePlanData extends Data
{
    public function __construct(
        public string $kind,
        public string $date,
        public string $time,
        public string $title,
        public ?string $detail,
    ) {}
}
