<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\WastaysEventType;
use Carbon\CarbonImmutable;

/**
 * One thing that happened in wastays, read from one bundle row, with the moment it happened.
 */
final readonly class WastaysEvent
{
    /**
     * @param  string  $sourceTable  the bundle table the row comes from
     * @param  string  $sourceId  the wastays id of that row
     * @param  string|null  $bookingId  the wastays booking the row belongs to
     * @param  array<string, string|null>  $row
     */
    public function __construct(
        public WastaysEventType $type,
        public CarbonImmutable $at,
        public string $sourceTable,
        public string $sourceId,
        public ?string $bookingId,
        public array $row,
    ) {}
}
