<?php

namespace App\Services\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

/**
 * The Advance money held on each Unit folio of a reservation: its Completed Advance payments minus their refunds (a
 * refund copies the purpose of its payment), as decimal strings with two places, by unit id, only where it is above 0.
 * A payment line never moves, so when Room charges start to go to the Master folio this money stays behind on the unit
 * folio. `SyncRoutingRules` reports it so staff can transfer the room charges back or refund it. It reads only. See
 * docs/13-folio/03-routing.md#billing-mode-rules.
 */
class UnitAdvancesHeld
{
    /**
     * @return array<int, string> Amount by unit id
     */
    public function forReservation(Reservation $reservation): array
    {
        return Payment::query()
            ->join('folios', 'folios.id', '=', 'payments.folio_id')
            ->where('folios.reservation_id', $reservation->id)
            ->where('folios.type', FolioType::Unit)
            ->where('folios.status', '<>', FolioStatus::Voided)
            ->where('payments.purpose', PaymentPurpose::Advance)
            ->where('payments.status', PaymentStatus::Completed)
            ->groupBy('folios.reservation_unit_id')
            ->havingRaw('sum(case when payments.refund_of_id is null then payments.amount else -payments.amount end) > 0')
            ->orderBy('folios.reservation_unit_id')
            ->get([
                'folios.reservation_unit_id',
                DB::raw('sum(case when payments.refund_of_id is null then payments.amount else -payments.amount end)::numeric(14, 2) as held'),
            ])
            ->mapWithKeys(fn (Payment $row): array => [(int) $row->getAttribute('reservation_unit_id') => (string) $row->getAttribute('held')])
            ->all();
    }
}
