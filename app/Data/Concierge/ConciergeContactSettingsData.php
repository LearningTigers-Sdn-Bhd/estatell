<?php

namespace App\Data\Concierge;

use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * What staff edit on the Contact tab: the raw values, as stored. Times are `HH:MM`.
 */
class ConciergeContactSettingsData extends Data
{
    public function __construct(
        public bool $frontDeskOpen24h,
        public ?string $frontDeskOpensAt,
        public ?string $frontDeskClosesAt,
        public ?string $frontDeskWhatsapp,
        public ?string $afterHoursMessage,
        public ?string $dutyManagerPhone,
        public ?string $emergencyPhone,
        public ?string $emergencyServicesNumber,
        public ?string $emergencyInstructions,
    ) {}

    public static function fromProperty(Property $property): self
    {
        $settings = $property->settings;

        return new self(
            $settings?->front_desk_open_24h !== false,
            $settings?->front_desk_opens_at === null ? null : substr($settings->front_desk_opens_at, 0, 5),
            $settings?->front_desk_closes_at === null ? null : substr($settings->front_desk_closes_at, 0, 5),
            $settings?->front_desk_whatsapp,
            $settings?->after_hours_message,
            $settings?->duty_manager_phone,
            $settings?->emergency_phone,
            $settings?->emergency_services_number,
            $settings?->emergency_instructions,
        );
    }
}
