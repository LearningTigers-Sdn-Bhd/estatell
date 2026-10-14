<?php

namespace App\Data\Catalogues;

use App\Enums\TaxRateType;
use App\Models\Tax;
use Spatie\LaravelData\Data;

/**
 * The fields of a tax typed in its form. `rate` is a decimal string with two places. Every field is set on save, so
 * an Action with a partial change builds the full set with `fromModel()`.
 */
class TaxInputData extends Data
{
    public function __construct(
        public string $name,
        public string $code,
        public TaxRateType $rateType,
        public string $rate,
        public bool $isInclusive,
        public bool $foreignGuestsOnly,
        public bool $collectAtCheckIn,
        public ?string $registrationNumber,
        public bool $enabled,
    ) {}

    /**
     * The tax's current values, with `enabled` replaced when given.
     */
    public static function fromModel(Tax $tax, ?bool $enabled = null): self
    {
        return new self(
            name: $tax->name,
            code: $tax->code,
            rateType: $tax->rate_type,
            rate: (string) $tax->rate,
            isInclusive: $tax->is_inclusive,
            foreignGuestsOnly: $tax->foreign_guests_only,
            collectAtCheckIn: $tax->collect_at_check_in,
            registrationNumber: $tax->registration_number,
            enabled: $enabled ?? $tax->enabled,
        );
    }
}
