<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationGuestData;
use App\Data\Reservations\ReservationUnitOptionData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\UnitGuest;
use Illuminate\Support\Collection;

/**
 * The rows of the Guests tab with two queries' worth of data: the booker when they do not stay, one row for each guest
 * on a unit, and one row for each unit with no guest.
 */
class ReservationGuestRows
{
    /**
     * @param  list<ReservationUnitOptionData>  $units
     * @return list<ReservationGuestData>
     */
    public function rows(Reservation $reservation, array $units, bool $canViewGuest, bool $canOpenCard = false, bool $canBill = false): array
    {
        $options = collect($units)->keyBy('id');
        $occupants = UnitGuest::query()
            ->whereIn('reservation_unit_id', array_keys($options->all()))
            ->with('guest')
            ->orderBy('reservation_unit_id')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('reservation_unit_id');
        $booker = $reservation->booker;
        $bookerStays = $occupants->flatten()->contains(fn (UnitGuest $unitGuest): bool => $unitGuest->guest_id === $booker->id);
        $folios = Folio::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->whereIn('type', [FolioType::Unit, FolioType::ThirdParty])
            ->with('payerGuest:id,full_name', 'payerCompany:id,name')
            ->get();
        $unitFolios = $folios->where('type', FolioType::Unit)->sortBy('id')->unique('reservation_unit_id')->keyBy('reservation_unit_id');
        $ownFolioGuestIds = $folios->where('type', FolioType::ThirdParty)->where('status', FolioStatus::Open)->pluck('payer_guest_id')->filter()->all();
        $rows = [];

        if (! $bookerStays) {
            $rows[] = new ReservationGuestData($canViewGuest ? $booker->id : null, $booker->full_name, $canViewGuest ? $booker->phone : null, null, null, null, [__('Booker')]);
        }

        foreach ($options as $unitId => $unit) {
            /** @var Collection<int, UnitGuest> $guests */
            $guests = $occupants->get($unitId, collect());

            if ($guests->isEmpty()) {
                $rows[] = new ReservationGuestData(null, null, null, $unitId, $unit->label, $unit->status, []);

                continue;
            }

            foreach ($guests as $unitGuest) {
                $rows[] = new ReservationGuestData(
                    guestId: $canViewGuest ? $unitGuest->guest_id : null,
                    name: $unitGuest->guest->full_name,
                    phone: $canViewGuest ? $unitGuest->guest->phone : null,
                    unitId: $unitId,
                    unitLabel: $unit->label,
                    unitStatus: $unit->status,
                    roles: array_values(array_filter([
                        $unitGuest->guest_id === $booker->id ? __('Booker') : null,
                        $unitGuest->is_primary ? __('Primary') : null,
                    ])),
                    cardUnitGuestId: $canOpenCard ? $unitGuest->id : null,
                    billing: $this->billing($unitGuest, $unitFolios->get($unitId), $ownFolioGuestIds),
                    canBill: $canBill
                        && in_array($unit->status->value, [UnitStatus::Confirmed->value, UnitStatus::CheckedIn->value, UnitStatus::CheckedOut->value], true)
                        && $this->billable($unitGuest, $unitFolios->get($unitId), $ownFolioGuestIds),
                );
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ownFolioGuestIds  Guests that pay an Open third-party folio of the reservation.
     */
    private function billing(UnitGuest $unitGuest, ?Folio $unitFolio, array $ownFolioGuestIds): ?string
    {
        if ($unitGuest->is_primary && $unitFolio !== null && $unitFolio->payer_guest_id === $unitGuest->guest_id) {
            return __('Pays unit folio');
        }

        if ($unitGuest->is_primary && $unitFolio !== null && $unitFolio->payer_guest_id !== $unitGuest->guest_id) {
            $payer = $unitFolio->payerGuest?->full_name ?? $unitFolio->payerCompany?->name;

            return $payer === null ? null : __('Unit folio billed to :name', ['name' => $payer]);
        }

        return ! $unitGuest->is_primary && in_array($unitGuest->guest_id, $ownFolioGuestIds, true) ? __('Own folio') : null;
    }

    /**
     * @param  list<int>  $ownFolioGuestIds
     */
    private function billable(UnitGuest $unitGuest, ?Folio $unitFolio, array $ownFolioGuestIds): bool
    {
        if (! $unitGuest->is_primary) {
            return ! in_array($unitGuest->guest_id, $ownFolioGuestIds, true);
        }

        return $unitFolio === null || ($unitFolio->status === FolioStatus::Open && $unitFolio->payer_guest_id !== $unitGuest->guest_id);
    }
}
