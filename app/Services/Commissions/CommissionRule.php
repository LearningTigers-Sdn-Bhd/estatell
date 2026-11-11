<?php

namespace App\Services\Commissions;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One service's commission rule, chosen by `CommissionService::rule()`. It names the reservations it may charge, decides
 * whether an ended reservation pays on its due date, and on what amount before tax.
 * See docs/30-commission-fees/02-commission-rules.md#code-shape.
 */
interface CommissionRule
{
    /**
     * The reservations this service may charge. The hourly run narrows it to ended and changed ones.
     *
     * @return Builder<Reservation>
     */
    public function candidates(): Builder;

    public function appliesTo(Reservation $reservation, CarbonImmutable $dueDate): bool;

    /**
     * The amount before tax the commission is charged on, as a decimal string.
     */
    public function base(Reservation $reservation): string;
}
