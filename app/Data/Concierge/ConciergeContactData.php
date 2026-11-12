<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The front desk and emergency contacts a guest reads. `deskOpen` is null when the property has not set its hours. The duty
 * manager and the out-of-hours message are only given while the desk is closed.
 */
class ConciergeContactData extends Data
{
    public function __construct(
        public ?bool $deskOpen,
        public ?string $deskHours,
        public ?string $whatsappUrl,
        public ?string $whatsappNumber,
        public ?string $afterHoursMessage,
        public ?string $dutyManagerPhone,
        public ?string $emergencyPhone,
        public ?string $emergencyServicesNumber,
        public ?string $emergencyInstructions,
        public ?string $deskPhone,
        public ?string $deskEmail,
        public ?string $deskExtension,
    ) {}
}
