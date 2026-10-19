<?php

namespace App\Actions\Folio;

use App\Models\Reservation;
use App\Services\Folio\OldFolioPage;

class PrepareOldFolios
{
    public function __construct(
        private OldFolioPage $oldFolioPage,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Give every checked-in or checked-out unit that has nights before the go-live date its own Unit folio, so the old
     * folio page has a tab for it (nothing creates a folio before the first posting). `CreateMigratedStay` calls it in
     * Copy line by line mode; opening the old folio page does not (it is read only, and `SaveOldFolios` creates the
     * folio of a tab that has none). A unit that has a folio is left alone, so a repeat creates nothing. It posts
     * nothing and logs nothing. Returns the number of folios created.
     * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
     */
    public function __invoke(Reservation $reservation): int
    {
        $created = 0;

        foreach ($this->oldFolioPage->unitsWithoutFolio($reservation) as $unit) {
            ($this->createFolio)($reservation, $unit);
            $created++;
        }

        return $created;
    }
}
