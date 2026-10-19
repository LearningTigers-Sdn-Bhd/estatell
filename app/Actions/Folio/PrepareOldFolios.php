<?php

namespace App\Actions\Folio;

use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Services\Folio\FolioRouter;
use App\Services\NightAudit\FirstBusinessDate;

class PrepareOldFolios
{
    public function __construct(
        private FirstBusinessDate $firstBusinessDate,
        private FolioRouter $folioRouter,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Give every checked-in or checked-out unit that has nights before the go-live date its own Unit folio, so the old
     * folio page has a tab for it (nothing creates a folio before the first posting). A unit that has a folio is left
     * alone, so a repeat creates nothing. It posts nothing and logs nothing. Returns the number of folios created.
     * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
     */
    public function __invoke(Reservation $reservation): int
    {
        $goLive = $this->firstBusinessDate->date($reservation->property);

        if ($goLive === null) {
            return 0;
        }

        $created = 0;

        foreach ($reservation->units()->whereIn('status', [UnitStatus::CheckedIn, UnitStatus::CheckedOut])->where('arrival_date', '<', $goLive->toDateString())->orderBy('id')->get() as $unit) {
            if ($this->folioRouter->unitFolio($unit) === null) {
                ($this->createFolio)($reservation, $unit);
                $created++;
            }
        }

        return $created;
    }
}
