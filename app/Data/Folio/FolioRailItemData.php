<?php

namespace App\Data\Folio;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One entry of the folio rail. `key` is the folio id, or `u{unit id}` for a unit that has no folio yet. `kind` is
 * master, unit, third_party or not_started. A not-started entry has no status and no balance.
 */
class FolioRailItemData extends Data
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public ?string $subtitle,
        public ?BadgeData $status,
        public ?string $balance,
    ) {}
}
