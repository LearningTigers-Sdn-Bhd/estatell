<?php

namespace App\Data\Catalogues;

use App\Enums\TaxRateType;
use App\Models\Tax;
use Spatie\LaravelData\Data;

/** A tax that may apply to a charge item. */
class ChargeItemTaxData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public TaxRateType $rateType,
        public string $rate,
        public bool $isInclusive,
        public bool $foreignGuestsOnly,
        public bool $enabled,
    ) {}

    public static function fromModel(Tax $tax): self
    {
        return new self(
            id: $tax->id,
            name: $tax->name,
            rateType: $tax->rate_type,
            rate: $tax->rate,
            isInclusive: $tax->is_inclusive,
            foreignGuestsOnly: $tax->foreign_guests_only,
            enabled: $tax->enabled,
        );
    }
}
