<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\ReservationUnit;

/**
 * Chooses the folio a posting lands on. This first version always chooses the unit's own folio, or null before the
 * unit has one (`PostCharge` then creates it). The routing item adds `folio_routing_rules`.
 * See docs/13-folio/02-posting-charges.md.
 */
class FolioRouter
{
    public function targetFor(ReservationUnit $unit): ?Folio
    {
        return Folio::query()
            ->where('property_id', $unit->property_id)
            ->where('reservation_unit_id', $unit->id)
            ->where('type', FolioType::Unit)
            ->oldest('id')
            ->first();
    }
}
