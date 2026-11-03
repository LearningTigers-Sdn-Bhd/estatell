<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use Closure;

/**
 * Who pays the taxes flagged **Collect at check-in** (the tourism tax) on a room night. They follow the room charge,
 * except when the room charge goes to a folio a company pays that is not the unit's own folio, and the company does not
 * pay collected taxes (`companies.pays_collected_taxes`, off by default): then the guest pays them at check-in, and they
 * post on the unit's own folio as children of the room line. See docs/13-folio/03-routing.md#collected-taxes.
 */
class CollectedTaxPayer
{
    /**
     * The ids of the taxes of a room night of `$unit` that post on the unit's own folio when the room line posts on
     * `$chargeFolio`. Empty when they follow the room line.
     *
     * @return list<int>
     */
    public function guestTaxIds(ReservationUnit $unit, ?Folio $chargeFolio): array
    {
        if ($chargeFolio === null || ! $this->guestPays($unit, $chargeFolio)) {
            return [];
        }

        return $this->collectedTaxIds($unit->property_id);
    }

    /**
     * The same answer as `guestTaxIds()` for many nights of one reservation, with its folios and taxes read once. The
     * closure takes the id of the folio the room line goes to (null while the unit has no folio). It reads nothing for
     * a reservation with no company, so the Scheduled rows of a third-party folio a company pays, on a reservation with
     * no company, keep their tax until the night posts.
     *
     * @return Closure(ReservationUnit, ?int): list<int>
     */
    public function resolverFor(Reservation $reservation): Closure
    {
        $folios = null;
        $taxIds = null;

        return function (ReservationUnit $unit, ?int $chargeFolioId) use ($reservation, &$folios, &$taxIds): array {
            if ($chargeFolioId === null || $reservation->company_id === null) {
                return [];
            }

            $folios ??= Folio::query()
                ->where('reservation_id', $reservation->id)
                ->whereNotNull('payer_company_id')
                ->with('payerCompany')
                ->get()
                ->keyBy('id');
            $folio = $folios->get($chargeFolioId);

            if ($folio === null || ! $this->guestPays($unit, $folio)) {
                return [];
            }

            return $taxIds ??= $this->collectedTaxIds($unit->property_id);
        };
    }

    /**
     * True when the unit's guest pays the collected taxes of the room nights that charge to the Master folio, so
     * Collect now offers them at check-in.
     */
    public function guestPaysOnMaster(ReservationUnit $unit, ?Folio $master): bool
    {
        return $master !== null && $this->guestPays($unit, $master) && $this->collectedTaxIds($unit->property_id) !== [];
    }

    private function guestPays(ReservationUnit $unit, Folio $folio): bool
    {
        if ($folio->type === FolioType::Unit && $folio->reservation_unit_id === $unit->id) {
            return false;
        }

        $company = $folio->loadMissing('payerCompany')->payerCompany;

        return $company !== null && ! $company->pays_collected_taxes;
    }

    /**
     * @return list<int>
     */
    private function collectedTaxIds(int $propertyId): array
    {
        return array_values(array_map(intval(...), Tax::query()
            ->where('property_id', $propertyId)
            ->where('enabled', true)
            ->where('collect_at_check_in', true)
            ->orderBy('id')
            ->pluck('id')
            ->all()));
    }
}
