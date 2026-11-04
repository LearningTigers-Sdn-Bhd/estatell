<?php

namespace App\Services\Kiosk;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Services\FrontDesk\StayDay;
use App\Services\NightAudit\CurrentBusinessDate;

/**
 * Finds the stay a kiosk works on and the guest who checks in. A stay is a reservation unit that is still Confirmed
 * and inside its check-in window (`window()`). Every query is scoped to the token's property, so a stay of another
 * property is the same as a missing one. Reads only.
 */
class KioskStays
{
    public const string OPEN = 'open';

    public const string EARLY = 'early';

    public const string ENDED = 'ended';

    /** The property has no open business date yet. */
    public const string NOT_LIVE = 'not_live';

    public function __construct(private CurrentBusinessDate $currentBusinessDate, private StayDay $stayDay) {}

    /**
     * The one check-in window rule of the kiosk, the same as `CheckInUnit`: the stay day (`StayDay`, not the calendar
     * date) is on or after the arrival date and before the departure date (a day use stay: on its day).
     *
     * @return self::OPEN|self::EARLY|self::ENDED|self::NOT_LIVE
     */
    public function window(ReservationUnit $stay): string
    {
        $businessDate = $this->currentBusinessDate->date($stay->property()->firstOrFail());

        if ($businessDate === null) {
            return self::NOT_LIVE;
        }

        $day = $this->stayDay->forCheckIn($stay, $businessDate)->toDateString();
        $arrival = $stay->arrival_date->toDateString();
        $ended = $stay->stay_type === StayType::DayUse ? $day > $arrival : $day >= $stay->departure_date->toDateString();

        return match (true) {
            $ended => self::ENDED,
            $day < $arrival => self::EARLY,
            default => self::OPEN,
        };
    }

    /**
     * @return ReservationUnit|null The Confirmed stay with that id in the property, only while its window is open.
     *                              A stay outside the window is the same as a missing one.
     */
    public function find(Property $property, int $id): ?ReservationUnit
    {
        $stay = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->find($id);

        return $stay !== null && $this->window($stay) === self::OPEN ? $stay : null;
    }

    /**
     * The Confirmed stay of a reservation reference (any letter case). A reservation with several stays gives the one
     * that arrives first.
     */
    public function findByReference(Property $property, string $reference): ?ReservationUnit
    {
        return ReservationUnit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::Confirmed)
            ->whereHas('reservation', fn ($query) => $query
                ->where('property_id', $property->id)
                ->whereRaw('lower(reference) = ?', [mb_strtolower(trim($reference))]))
            ->orderBy('arrival_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * The primary guest of the stay, or the booker when it has none (the same person `UnitNationality` reads). Null
     * when that guest was merged into another guest or anonymized.
     */
    public function guest(ReservationUnit $unit): ?Guest
    {
        $primary = $unit->guests()->where('is_primary', true)->with('guest')->first();
        $guest = $primary === null ? $unit->reservation()->firstOrFail()->booker()->first() : $primary->guest;

        return $guest !== null && $guest->merged_into_id === null && $guest->anonymized_at === null ? $guest : null;
    }

    /**
     * The primary guest row of the stay: the one who signs the registration card. Null when the stay has none (the
     * booker is then the guest, and no card is needed).
     */
    public function primaryUnitGuest(ReservationUnit $unit): ?UnitGuest
    {
        return $unit->guests()->where('is_primary', true)->first();
    }
}
