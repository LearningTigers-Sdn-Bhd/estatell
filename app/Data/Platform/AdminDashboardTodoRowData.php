<?php

namespace App\Data\Platform;

use App\Enums\Tone;
use Spatie\LaravelData\Data;

/**
 * One To do row on the admin dashboard: a count, what it counts, and the filtered list behind it.
 */
class AdminDashboardTodoRowData extends Data
{
    public function __construct(
        public string $key,
        public int $count,
        public string $label,
        /** A property name for rows about one property, else null. */
        public ?string $detail,
        public string $href,
        public Tone $tone,
    ) {}
}
