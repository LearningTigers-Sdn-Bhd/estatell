<?php

namespace App\Data\Catalogues;

use Spatie\LaravelData\Data;

/** One tax row of the room revenue posting preview. A null amount means the tax does not apply to that guest. */
class RoomRevenuePreviewLineData extends Data
{
    public function __construct(
        public int $taxId,
        public string $name,
        public bool $inclusive,
        public ?string $localAmount,
        public ?string $foreignAmount,
    ) {}
}
