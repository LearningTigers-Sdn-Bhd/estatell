<?php

namespace App\Actions\Folio;

use App\Models\Folio;
use App\Models\Guest;

class RepointGuestFolios
{
    /**
     * Point the folios that a merged guest pays at the survivor. Folio lines do not change.
     *
     * Called by `MergeGuests`, inside its transaction.
     *
     * @return array{folios: int}
     */
    public function __invoke(Guest $survivor, Guest $merged): array
    {
        $folios = Folio::query()
            ->where('payer_guest_id', $merged->id)
            ->update(['payer_guest_id' => $survivor->id]);

        return ['folios' => $folios];
    }
}
