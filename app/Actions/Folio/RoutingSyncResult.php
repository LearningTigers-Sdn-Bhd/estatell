<?php

namespace App\Actions\Folio;

use App\Models\Folio;

/**
 * What `SyncRoutingRules` returns. `masterFolio` is the active Master folio after the sync (null when the reservation
 * has none). `unitsWithPostedRoomLines` lists the units that already hold posted room lines on their own folio while a
 * BillingMode rule now sends room charges to the Master folio: those lines stay where they are, and only later postings
 * follow the rule. `unitAdvances` is the Advance money each unit folio holds (amount by unit id) when the sync starts
 * sending room charges to the Master folio: a payment line never moves, so staff transfer the room charges back or
 * refund it. Both lists are filled only when the sync created a BillingMode rule, so a sync that changes nothing reports
 * nothing.
 */
class RoutingSyncResult
{
    /**
     * @param  list<int>  $unitsWithPostedRoomLines
     * @param  array<int, string>  $unitAdvances
     */
    public function __construct(
        public readonly ?Folio $masterFolio,
        public readonly bool $masterFolioCreated,
        public readonly int $rulesCreated,
        public readonly int $rulesRemoved,
        public readonly array $unitsWithPostedRoomLines,
        public readonly array $unitAdvances = [],
    ) {}
}
