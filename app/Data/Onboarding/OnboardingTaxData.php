<?php

namespace App\Data\Onboarding;

use App\Enums\TaxRateType;
use App\Models\Tax;
use Spatie\LaravelData\Data;

/**
 * One saved tax as a row of the onboarding Taxes table. A preset owns a system posting code, so the table turns it
 * off instead of removing it.
 */
class OnboardingTaxData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public TaxRateType $rateType,
        public string $rate,
        public bool $isInclusive,
        public bool $foreignGuestsOnly,
        public bool $collectAtCheckIn,
        public ?string $registrationNumber,
        public bool $enabled,
        public bool $isPreset,
        public bool $appliesToRooms,
    ) {}

    public static function fromModel(Tax $tax, bool $appliesToRooms): self
    {
        return new self(
            id: $tax->id,
            name: $tax->name,
            rateType: $tax->rate_type,
            rate: $tax->rate,
            isInclusive: $tax->is_inclusive,
            foreignGuestsOnly: $tax->foreign_guests_only,
            collectAtCheckIn: $tax->collect_at_check_in,
            registrationNumber: $tax->registration_number,
            enabled: $tax->enabled,
            isPreset: $tax->transactionCode->system_key !== null,
            appliesToRooms: $appliesToRooms,
        );
    }
}
