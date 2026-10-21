<?php

namespace App\Data\Channels;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One line of the OTAs card on the admin Channels tab. `status` is null while the OTA is not connected, and `note`
 * explains an OTA that cannot be connected here.
 */
class ChannelOtaRowData extends Data
{
    public function __construct(
        public int $otaChannelId,
        public string $name,
        public ?string $hotelId,
        public ?BadgeData $status,
        public ?int $connectionId,
        public bool $canConnect,
        public ?string $note,
        public ?string $lastError,
        public bool $canActivate,
        public bool $canPause,
        public bool $canResume,
        public bool $canImport,
    ) {}
}
