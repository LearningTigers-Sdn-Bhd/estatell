<?php

namespace App\Data\Pos;

use Spatie\LaravelData\Data;

/**
 * How staff settle a PendingReview posting: `attach` to a unit (and optionally one of its open folios), or `ignore`
 * with a note.
 */
class ResolvePosPostingData extends Data
{
    public function __construct(
        public string $action,
        public ?int $reservationUnitId = null,
        public ?int $folioId = null,
        public ?string $note = null,
    ) {}
}
