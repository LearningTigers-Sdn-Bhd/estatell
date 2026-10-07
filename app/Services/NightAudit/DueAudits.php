<?php

namespace App\Services\NightAudit;

use App\Enums\BusinessDateStatus;
use App\Enums\PropertyStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The properties whose automatic night audit is due: Live, auto audit on, an Open business date and a local clock at or past
 * `audit_time` on the day after that date. The check runs on the local clock of each property, so no scheduler time zone is used.
 */
class DueAudits
{
    /**
     * @return Collection<int, Property>
     */
    public function get(?CarbonImmutable $now = null): Collection
    {
        $now ??= now();

        return Property::query()
            ->where('status', PropertyStatus::Live->value)
            ->whereHas('settings', fn ($query) => $query->where('auto_audit_enabled', true))
            ->with(['settings', 'businessDates' => fn ($query) => $query->current()])
            ->get()
            ->filter(fn (Property $property): bool => $this->isDue($property, $now))
            ->values();
    }

    private function isDue(Property $property, CarbonImmutable $now): bool
    {
        $businessDate = $property->businessDates->first();
        $settings = $property->settings;

        if ($businessDate === null || $settings === null || $businessDate->status !== BusinessDateStatus::Open) {
            return false;
        }

        $dueAt = CarbonImmutable::parse($businessDate->date->toDateString(), $settings->time_zone)
            ->addDay()
            ->setTimeFromTimeString($settings->audit_time);

        return $now->greaterThanOrEqualTo($dueAt);
    }
}
