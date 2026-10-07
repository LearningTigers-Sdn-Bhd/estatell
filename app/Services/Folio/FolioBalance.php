<?php

namespace App\Services\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;

/**
 * Calculates folio balances from the ledger. A balance is `SUM(folio_lines.amount)`: charges and taxes are
 * positive, payments negative, so a positive balance is what the payer still owes. Nothing is stored.
 */
class FolioBalance
{
    /**
     * The balance of one folio as a decimal string with two places.
     */
    public function forFolio(Folio $folio): string
    {
        return (string) FolioLine::query()
            ->where('folio_id', $folio->id)
            ->selectRaw('COALESCE(SUM(amount), 0)::numeric(14, 2) AS balance')
            ->value('balance');
    }

    /**
     * The balance of every reservation: the sum of its folios that are not Voided, as decimal strings with two places.
     * A reservation with no such folio has 0.00.
     *
     * @param  list<int>  $reservationIds
     * @return array<int, string> By reservation id
     */
    public function forReservations(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        $balances = Folio::query()
            ->whereIn('folios.reservation_id', $reservationIds)
            ->where('folios.status', '!=', FolioStatus::Voided)
            ->join('folio_lines', 'folio_lines.folio_id', '=', 'folios.id')
            ->groupBy('folios.reservation_id')
            ->selectRaw('folios.reservation_id, COALESCE(SUM(folio_lines.amount), 0)::numeric(14, 2) AS balance')
            ->pluck('balance', 'reservation_id');

        return collect($reservationIds)
            ->mapWithKeys(fn (int $id): array => [$id => (string) ($balances[$id] ?? '0.00')])
            ->all();
    }

    /**
     * Whether any folio that the guest pays, in the guest's property, has a balance other than 0.
     * Voided folios are skipped.
     */
    public function guestHasOpenBalance(Guest $guest): bool
    {
        return Folio::query()
            ->where('property_id', $guest->property_id)
            ->where('payer_guest_id', $guest->id)
            ->where('status', '!=', FolioStatus::Voided)
            ->whereIn('id', FolioLine::query()->select('folio_id')->groupBy('folio_id')->havingRaw('SUM(amount) <> 0'))
            ->exists();
    }
}
