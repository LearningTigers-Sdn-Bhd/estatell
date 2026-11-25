<?php

namespace App\Services\AiAssistant;

use App\Models\AiTurn;
use App\Models\Property;

/**
 * What the property's AI assistant cost in the current calendar month (property time zone), and whether that reached the
 * monthly cap. See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class AiMonthlyUsage
{
    public function micros(Property $property): int
    {
        $timeZone = $property->settings->time_zone;
        $start = now($timeZone)->startOfMonth()->utc();

        return (int) AiTurn::query()
            ->where('property_id', $property->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->setTimezone($timeZone)->addMonth()->utc())
            ->sum('cost_micros');
    }

    /**
     * True when the property has a cap and this month's cost is at or over it. No cap is never reached.
     */
    public function capReached(Property $property): bool
    {
        $cap = $property->settings->ai_monthly_cap_micros;

        return $cap !== null && $this->micros($property) >= $cap;
    }
}
