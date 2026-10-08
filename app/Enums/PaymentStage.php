<?php

namespace App\Enums;

/**
 * Where a stay is when money is taken, which decides the purposes a folio offers. See
 * docs/14-payments/01-payments-and-refunds.md#purposes-by-stage.
 */
enum PaymentStage: string
{
    case BeforeArrival = 'before_arrival';
    case InHouse = 'in_house';
    case Departed = 'departed';

    /**
     * A unit's stage: Confirmed is BeforeArrival, CheckedIn is InHouse, and CheckedOut, Cancelled and NoShow are
     * Departed (a cancel or no-show fee is settled).
     */
    public static function forUnit(UnitStatus $status): self
    {
        return match ($status) {
            UnitStatus::Confirmed => self::BeforeArrival,
            UnitStatus::CheckedIn => self::InHouse,
            UnitStatus::CheckedOut, UnitStatus::Cancelled, UnitStatus::NoShow => self::Departed,
        };
    }

    /**
     * The Master folio's stage from its live units (not Cancelled or NoShow): any CheckedIn is InHouse, all
     * Confirmed is BeforeArrival, otherwise Departed.
     *
     * @param  list<UnitStatus>  $liveStatuses
     */
    public static function forMaster(array $liveStatuses): self
    {
        return match (true) {
            in_array(UnitStatus::CheckedIn, $liveStatuses, true) => self::InHouse,
            $liveStatuses !== [] && collect($liveStatuses)->every(fn (UnitStatus $status): bool => $status === UnitStatus::Confirmed) => self::BeforeArrival,
            default => self::Departed,
        };
    }
}
