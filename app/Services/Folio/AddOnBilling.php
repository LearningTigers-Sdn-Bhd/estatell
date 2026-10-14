<?php

namespace App\Services\Folio;

use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Where each add-on of a reservation is billed, for the Add-ons tab. A posted add-on names the folio that holds its
 * line. An add-on that is not posted yet, on a unit that can still post, names its folio only when routing sends it
 * away from the unit's own folio (for example to the Master). It reads in a fixed number of queries and writes nothing.
 * See docs/12-reservations/04-add-ons.md.
 */
class AddOnBilling
{
    public function __construct(
        private FolioRouter $folioRouter,
        private FolioStatement $folioStatement,
    ) {}

    /**
     * `folioLine` and `chargeItem.transactionCode` must be loaded on each add-on. The result is keyed by add-on id.
     *
     * @param  Collection<int, UnitAddOn>  $addOns
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @param  array<int, string>  $labels  Unit label by unit id
     * @return array<int, array{folio: ?string, routedTo: ?string}>
     */
    public function for(Reservation $reservation, Collection $addOns, Collection $units, array $labels): array
    {
        $folios = Folio::query()
            ->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)
            ->with('payerGuest', 'payerCompany:id,name')
            ->get()
            ->keyBy('id');
        $unitFolioIds = $folios->where('type', FolioType::Unit)->pluck('id', 'reservation_unit_id');
        $resolver = $this->folioRouter->resolverFor($reservation);
        $unitsById = $units->keyBy('id');
        $result = [];

        foreach ($addOns as $addOn) {
            $folio = null;
            $routedTo = null;

            if ($addOn->folio_line_id !== null) {
                $line = $addOn->folioLine;
                $folio = $line === null ? null : $this->label($folios->get($line->folio_id), $labels);
            } else {
                $unit = $unitsById->get($addOn->reservation_unit_id);

                if ($unit !== null && in_array($unit->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                    $targetId = $resolver($unit, $addOn->chargeItem->transactionCode);

                    if ($targetId !== null && $targetId !== $unitFolioIds->get($unit->id)) {
                        $routedTo = $this->label($folios->get($targetId), $labels);
                    }
                }
            }

            $result[$addOn->id] = ['folio' => $folio, 'routedTo' => $routedTo];
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function label(?Folio $folio, array $labels): ?string
    {
        return $folio === null ? null : $this->folioStatement->optionLabel($folio, $labels);
    }
}
