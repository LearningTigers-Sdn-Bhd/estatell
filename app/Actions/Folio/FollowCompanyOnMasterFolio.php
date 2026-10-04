<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;

class FollowCompanyOnMasterFolio
{
    /**
     * Point the active Master folio's payer at a new company (null clears it). Returns false and changes nothing when
     * the Master folio holds any line: its charges must move or be voided first. No Master folio is a success, because
     * `SyncRoutingRules` creates it with the reservation's company. Call it before the reservation's `company_id`
     * changes, inside the caller's transaction. See docs/15-city-ledger/01-companies.md.
     */
    public function __invoke(Reservation $reservation, ?int $companyId): bool
    {
        $master = Folio::query()
            ->where('reservation_id', $reservation->id)
            ->where('type', FolioType::Master)
            ->where('status', '<>', FolioStatus::Voided)
            ->oldest('id')
            ->lockForUpdate()
            ->first();

        if ($master === null) {
            return true;
        }

        if (FolioLine::query()->where('folio_id', $master->id)->exists()) {
            return false;
        }

        $master->payer_company_id = $companyId;
        $master->save();

        return true;
    }
}
