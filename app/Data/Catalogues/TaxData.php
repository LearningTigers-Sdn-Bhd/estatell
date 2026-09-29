<?php

namespace App\Data\Catalogues;

use App\Enums\TaxRateType;
use App\Models\Tax;
use Spatie\LaravelData\Data;

class TaxData extends Data
{
    /** @param list<int> $applicableTransactionCodeIds */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public TaxRateType $rateType,
        public string $rate,
        public bool $isInclusive,
        public bool $foreignGuestsOnly,
        public ?string $registrationNumber,
        public bool $enabled,
        public array $applicableTransactionCodeIds,
        public string $postingTransactionCode,
    ) {}

    public static function fromModel(Tax $tax): self
    {
        return new self(
            id: $tax->id,
            name: $tax->name,
            code: $tax->code,
            rateType: $tax->rate_type,
            rate: $tax->rate,
            isInclusive: $tax->is_inclusive,
            foreignGuestsOnly: $tax->foreign_guests_only,
            registrationNumber: $tax->registration_number,
            enabled: $tax->enabled,
            applicableTransactionCodeIds: array_values($tax->applicableTransactionCodes
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->all()),
            postingTransactionCode: $tax->transactionCode->code,
        );
    }
}
