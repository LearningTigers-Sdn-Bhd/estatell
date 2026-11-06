<?php

namespace App\Data\CityLedger;

use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * The property's agent payment terms, which a company follows where its own are empty.
 */
class AgentPaymentTermsData extends Data
{
    public function __construct(
        public int $depositPercent,
        public int $depositHours,
        public int $fullPaymentDays,
    ) {}

    /**
     * Null while the property has no settings.
     */
    public static function forProperty(Property $property): ?self
    {
        $settings = $property->settings;

        return $settings === null
            ? null
            : new self($settings->agent_deposit_percent, $settings->agent_deposit_hours, $settings->agent_full_payment_days);
    }
}
