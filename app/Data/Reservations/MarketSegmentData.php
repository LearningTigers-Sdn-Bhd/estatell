<?php

namespace App\Data\Reservations;

use App\Models\MarketSegment;
use Spatie\LaravelData\Data;

/**
 * A market segment: one row of Settings › Market segments, and one choice of the booking form picker.
 * See docs/12-reservations/15-market-segments.md.
 */
class MarketSegmentData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
    ) {}

    public static function fromModel(MarketSegment $segment): self
    {
        return new self($segment->id, $segment->name, $segment->active);
    }
}
