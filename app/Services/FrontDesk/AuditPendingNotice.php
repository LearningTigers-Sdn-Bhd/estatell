<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\UnitAuditPendingData;
use App\Enums\BusinessDateStatus;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;

/**
 * The warning strip of the front desk forms while the night audit is pending, and the sentences under the stay that say
 * what it means for this guest. It reads only. See docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
 */
class AuditPendingNotice
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private StayDay $stayDay,
    ) {}

    /**
     * The strip with the given ⓘ line, or null when the audit is not pending.
     */
    public function block(Property $property, ?string $notice = null): ?UnitAuditPendingData
    {
        $current = $this->currentBusinessDate->get($property);

        if ($current === null) {
            return null;
        }

        $businessDate = CarbonImmutable::parse($current->date->toDateString());

        if (! $this->stayDay->isAuditPending($property, $businessDate)) {
            return null;
        }

        return new UnitAuditPendingData(
            $businessDate->format('j M'),
            $businessDate->addDay()->format('j M'),
            $current->status === BusinessDateStatus::Auditing,
            $notice,
        );
    }

    /**
     * "21 Oct", "21 Oct and 22 Oct" or "21 Oct, 22 Oct and 23 Oct".
     *
     * @param  list<CarbonImmutable>  $dates
     */
    public function dates(array $dates): string
    {
        $names = array_map(fn (CarbonImmutable $date): string => $date->format('j M'), $dates);
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }
}
