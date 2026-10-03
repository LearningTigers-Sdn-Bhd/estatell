<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `SyncRoutingRules` returns. `masterFolio` is the active Master folio after the sync (null when the reservation
 * has none). `unitsWithPostedRoomLines` lists the units that already hold posted room lines on their own folio while a
 * BillingMode rule now sends room charges to the Master folio: those lines stay where they are, and only later postings
 * follow the rule.
 */
class RoutingSyncResult
{
    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     */
    public function __construct(
        public readonly ?Folio $masterFolio,
        public readonly bool $masterFolioCreated,
        public readonly int $rulesCreated,
        public readonly int $rulesRemoved,
        public readonly array $unitsWithPostedRoomLines,
    ) {}
}
