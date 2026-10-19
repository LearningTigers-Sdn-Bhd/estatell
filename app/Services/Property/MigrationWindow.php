<?php

namespace App\Services\Property;

use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use Carbon\CarbonImmutable;

/**
 * The days after go-live when staff may bring in stays, deposits and balances from the old system. The window is open
 * while the business date is before the go-live date plus `migration_window_days`. It is closed before go-live and when
 * the setting is 0. See docs/17-onboarding/07-migration.md.
 */
class MigrationWindow
{
    public function __construct(
        private FirstBusinessDate $firstBusinessDate,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    public function isOpen(Property $property): bool
    {
        $closesOn = $this->closesOn($property);
        $today = $this->currentBusinessDate->date($property);

        return $closesOn !== null && $today !== null && $today->lessThan($closesOn);
    }

    /** The first day the window is closed: the go-live date plus the window. Null before go-live or with a window of 0. */
    public function closesOn(Property $property): ?CarbonImmutable
    {
        $days = (int) $property->settings?->migration_window_days;
        $goLive = $this->firstBusinessDate->date($property);

        return $days === 0 || $goLive === null ? null : $goLive->addDays($days);
    }
}
