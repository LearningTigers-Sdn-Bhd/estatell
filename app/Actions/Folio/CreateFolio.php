<?php

namespace App\Actions\Folio;

use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\NextDocumentNumber;
use InvalidArgumentException;

class CreateFolio
{
    public function __construct(private NextDocumentNumber $nextDocumentNumber) {}

    /**
     * Create an Open folio. A unit gets a Unit folio paid by the booker. No unit gives the Master folio, paid by the
     * reservation's company (no payer without one). The number comes from the property's folio sequence. `PostCharge`
     * calls this the first time a unit receives a posting, and `SyncRoutingRules` calls it for the Master folio:
     * nothing creates folios at booking time. The caller makes sure a reservation gets one active Master folio (the
     * partial unique index rejects a second one). See docs/13-folio/02-posting-charges.md and docs/13-folio/03-routing.md.
     */
    public function __invoke(Reservation $reservation, ?ReservationUnit $unit): Folio
    {
        return $unit === null
            ? $this->create($reservation, FolioType::Master, null, null, $reservation->company_id)
            : $this->create($reservation, FolioType::Unit, $unit, $reservation->booker_guest_id, null);
    }

    /**
     * Create a ThirdParty folio: no unit and its own payer, for example a travel agent that pays only the diving
     * charges. Give exactly one payer, a guest or a company id. A reservation can have any number of them. The folio
     * has no label: the payer names it. See docs/13-folio/03-routing.md.
     *
     * @throws InvalidArgumentException when not exactly one payer is given, or the guest belongs to another property
     */
    public function thirdParty(Reservation $reservation, ?Guest $payerGuest, ?int $payerCompanyId = null): Folio
    {
        if (($payerGuest === null) === ($payerCompanyId === null)) {
            throw new InvalidArgumentException('A third party folio needs exactly one payer: a guest or a company.');
        }

        if ($payerGuest !== null && $payerGuest->property_id !== $reservation->property_id) {
            throw new InvalidArgumentException('The payer guest belongs to another property.');
        }

        return $this->create($reservation, FolioType::ThirdParty, null, $payerGuest?->id, $payerCompanyId);
    }

    private function create(Reservation $reservation, FolioType $type, ?ReservationUnit $unit, ?int $payerGuestId, ?int $payerCompanyId): Folio
    {
        $number = ($this->nextDocumentNumber)($reservation->property, DocumentType::Folio);

        $folio = new Folio([
            'reservation_id' => $reservation->id,
            'reservation_unit_id' => $unit?->id,
            'number' => $number->reference,
            'type' => $type,
            'status' => FolioStatus::Open,
            'payer_guest_id' => $payerGuestId,
            'payer_company_id' => $payerCompanyId,
        ]);
        $folio->property_id = $reservation->property_id;
        $folio->save();

        return $folio;
    }
}
