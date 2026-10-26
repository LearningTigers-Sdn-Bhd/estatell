<?php

namespace App\Services\Reservations;

use App\Actions\FrontDesk\WalkInResult;
use App\Actions\FrontDesk\WalkInUnitResult;
use App\Models\Property;
use App\Services\Payments\PaymentRefusals;
use Brick\Math\BigDecimal;

/**
 * The words of a saved walk-in: the toast headline and one reason for each unit that stayed Confirmed. It reads only.
 * The reasons are the ones the check-in Dialog shows (`ReservationRefusals`, `PaymentRefusals`), led by the unit's
 * room. See docs/12-reservations/05-booking-form.md#refusals-on-save.
 */
class WalkInOutcome
{
    public function __construct(
        private ReservationRefusals $refusals,
        private PaymentRefusals $paymentRefusals,
    ) {}

    /**
     * "Reservation R-1042: 3 of 3 checked in · MYR 943.20 collected". The amount is left out when nothing was taken.
     */
    public function headline(Property $property, WalkInResult $result): string
    {
        $headline = __('Reservation :reference: :done of :total checked in', [
            'reference' => $result->reservation?->reference,
            'done' => $result->checkedIn(),
            'total' => count($result->units),
        ]);

        if (! BigDecimal::of($result->collected)->isPositive()) {
            return $headline;
        }

        $property->loadMissing('settings');

        return $headline.' · '.__(':amount collected', ['amount' => trim($property->settings?->currency->getCurrencyCode().' '.number_format((float) $result->collected, 2))]);
    }

    /**
     * "Room 106: A registration card is not signed. ..." for each unit that stayed Confirmed, in unit order.
     *
     * @return list<string>
     */
    public function reasons(WalkInResult $result, bool $canOverrideCard): array
    {
        return array_values(array_map(
            fn (WalkInUnitResult $unit): string => $unit->label.': '.($unit->paymentRefused
                ? $this->paymentRefusals->message((string) $unit->refusal)
                : (string) current($this->refusals->unitErrors((string) $unit->refusal, canOverrideCard: $canOverrideCard))),
            array_filter($result->units, fn (WalkInUnitResult $unit): bool => ! $unit->isCheckedIn()),
        ));
    }
}
