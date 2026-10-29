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

    /**
     * When the automatic audit of a business date is due: `audit_time` on the day after that date, on the property's
     * clock. Null when the automatic audit is off.
     */
    public function dueAt(Property $property, CarbonImmutable $date): ?CarbonImmutable
    {
        $settings = $property->settings;

        if ($settings === null || ! $settings->auto_audit_enabled) {
            return null;
        }

        return CarbonImmutable::parse($date->toDateString(), $settings->time_zone)
            ->addDay()
            ->setTimeFromTimeString($settings->audit_time);
    }

    private function isDue(Property $property, CarbonImmutable $now): bool
    {
        $businessDate = $property->businessDates->first();

        if ($businessDate === null || $businessDate->status !== BusinessDateStatus::Open) {
            return false;
        }

        $dueAt = $this->dueAt($property, $businessDate->date);

        return $dueAt !== null && $now->greaterThanOrEqualTo($dueAt);
    }
}
