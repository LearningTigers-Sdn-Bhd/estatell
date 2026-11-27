<?php

namespace App\Services\Reports\BookingPerformance;

use App\Data\Shared\BadgeData;
use App\Enums\UnitStatus;
use App\Models\Property;

/**
 * By unit status, with the status badge. The only grouping that counts cancelled and no-show units.
 */
class StatusGrouping implements BookingPerformanceGrouping
{
    public function idOf(object $unit): string
    {
        return (string) $unit->status;
    }

    public function labels(Property $property, array $ids): array
    {
        $labels = [];

        foreach ($ids as $id) {
            $status = UnitStatus::from((string) $id);
            $labels[$id] = new GroupLabel($status->label(), status: BadgeData::fromUnitStatus($status));
        }

        return $labels;
    }

    public function noneLabel(): string
    {
        return __('No status');
    }
}
