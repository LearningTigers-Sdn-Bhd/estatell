<?php

namespace App\Data\Keyport;

use Spatie\LaravelData\Data;

/**
 * The key card panel of one stay: the eLock locks of its room, whether a desk PC is online, and the latest jobs.
 */
class UnitKeyCardsData extends Data
{
    /**
     * @param  list<array{id: int, name: string, hasCard: bool}>  $locks
     * @param  list<KeyportJobRowData>  $jobs
     */
    public function __construct(
        public array $locks,
        public bool $deskOnline,
        public bool $hasDesk,
        public bool $canMake,
        public array $jobs,
        public bool $syncing,
    ) {}
}
