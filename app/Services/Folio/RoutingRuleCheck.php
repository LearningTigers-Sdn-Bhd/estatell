<?php

namespace App\Services\Folio;

use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;

/**
 * Checks what a CHECK constraint cannot say about a routing rule: the target folio, the unit and the code must belong
 * to the rule's reservation (or property). `CreateRoutingRule` calls it, and the later staff screens can call it for
 * their form errors. See docs/13-folio/03-routing.md.
 */
class RoutingRuleCheck
{
    public const REFUSAL_TARGET_FOLIO_NOT_IN_RESERVATION = 'target_folio_not_in_reservation';

    public const REFUSAL_UNIT_NOT_IN_RESERVATION = 'unit_not_in_reservation';

    public const REFUSAL_TRANSACTION_CODE_NOT_FOUND = 'transaction_code_not_found';

    /**
     * The refusal code for a rule that must not be saved, or null when it is valid.
     */
    public function refusal(Reservation $reservation, Folio $targetFolio, ?int $reservationUnitId, ?int $transactionCodeId): ?string
    {
        if ($targetFolio->reservation_id !== $reservation->id) {
            return self::REFUSAL_TARGET_FOLIO_NOT_IN_RESERVATION;
        }

        if ($reservationUnitId !== null && ! ReservationUnit::query()->where('reservation_id', $reservation->id)->whereKey($reservationUnitId)->exists()) {
            return self::REFUSAL_UNIT_NOT_IN_RESERVATION;
        }

        if ($transactionCodeId !== null && ! TransactionCode::query()->where('property_id', $reservation->property_id)->whereKey($transactionCodeId)->exists()) {
            return self::REFUSAL_TRANSACTION_CODE_NOT_FOUND;
        }

        return null;
    }
}
