<?php

namespace App\Services\Folio;

use App\Models\Property;
use App\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * The days a manual line may take as its service date: from the later of the reservation's creation date and the
 * property's first business date, up to the current business date. A deposit taken before arrival is inside the range.
 * It only reads. See docs/13-folio/08-manual-folio.md.
 */
class ServiceDateRange
{
    /**
     * The first day a manual line may be dated.
     */
    public function earliest(Property $property, Reservation $reservation): ?CarbonImmutable
    {
        $first = $property->businessDates()->min('date');

        if ($first === null) {
            return null;
        }

        $created = CarbonImmutable::parse($reservation->created_at->toDateString());
        $opened = CarbonImmutable::parse((string) $first);

        return $created->greaterThan($opened) ? $created : $opened;
    }

    /**
     * Whether a `Y-m-d` date is a real day inside the range.
     */
    public function allows(Property $property, Reservation $reservation, CarbonImmutable $businessDate, string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || CarbonImmutable::createFromFormat('Y-m-d', $date)?->toDateString() !== $date) {
            return false;
        }

        $earliest = $this->earliest($property, $reservation);

        return $earliest !== null && $date >= $earliest->toDateString() && $date <= $businessDate->toDateString();
    }

    /**
     * Whether a `Y-m-d` date is a real day up to the business date. An Old system payment uses it: the day the old PMS
     * took the money has no earliest day, and it skips the range above.
     */
    public function allowsOldSystemPayment(CarbonImmutable $businessDate, string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || CarbonImmutable::createFromFormat('Y-m-d', $date)?->toDateString() !== $date) {
            return false;
        }

        return $date <= $businessDate->toDateString();
    }
}
