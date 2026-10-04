<?php

namespace App\Data\Staff;

use Spatie\LaravelData\Data;

/**
 * The copy message for selected staff, and how many had no usable temp password.
 */
class TemporaryPasswordMessageData extends Data
{
    public function __construct(
        public string $text,
        public int $count,
        public int $skipped,
    ) {}
}
