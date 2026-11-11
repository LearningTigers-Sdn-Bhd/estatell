<?php

namespace App\Services\Commissions;

use App\Models\Reservation;

/**
 * One service's commission rule, chosen by `CommissionService::rule()`. It decides whether an ended reservation pays the
 * commission and on what amount before tax. See docs/30-commission-fees/02-commission-rules.md#code-shape.
 */
interface CommissionRule
{
    public function appliesTo(Reservation $reservation): bool;

    /**
     * The amount before tax the commission is charged on, as a decimal string.
     */
    public function base(Reservation $reservation): string;
}
