<?php

namespace App\Data\Property;

use App\Models\Tax;
use Spatie\LaravelData\Data;

/**
 * A tax on the Header tab of the printing settings. A tax with no registration number cannot be picked.
 */
class PrintTaxData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public ?string $registrationNumber,
        public bool $enabled,
    ) {}

    public static function fromModel(Tax $tax): self
    {
        return new self(
            id: $tax->id,
            name: $tax->name,
            code: $tax->code,
            registrationNumber: $tax->registration_number,
            enabled: $tax->enabled,
        );
    }
}
