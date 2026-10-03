<?php

namespace App\Actions\Folio;

use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\NextDocumentNumber;

class CreateFolio
{
    public function __construct(private NextDocumentNumber $nextDocumentNumber) {}

    /**
     * Create an Open folio. A unit gets a Unit folio paid by the booker. No unit gives the Master folio. The number
     * comes from the property's folio sequence. `PostCharge` calls this the first time a unit receives a posting:
     * nothing creates folios at booking time. See docs/13-folio/02-posting-charges.md.
     */
    public function __invoke(Reservation $reservation, ?ReservationUnit $unit): Folio
    {
        $number = ($this->nextDocumentNumber)($reservation->property, DocumentType::Folio);

        $folio = new Folio([
            'reservation_id' => $reservation->id,
            'reservation_unit_id' => $unit?->id,
            'number' => $number->reference,
            'type' => $unit === null ? FolioType::Master : FolioType::Unit,
            'status' => FolioStatus::Open,
            'payer_guest_id' => $unit === null ? null : $reservation->booker_guest_id,
        ]);
        $folio->property_id = $reservation->property_id;
        $folio->save();

        return $folio;
    }
}
