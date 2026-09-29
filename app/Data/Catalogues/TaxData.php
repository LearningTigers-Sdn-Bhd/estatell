<?php

namespace App\Data\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\Tax;
use App\Models\TransactionCode;
use Spatie\LaravelData\Data;

class TaxData extends Data
{
    /** @param list<string> $appliesTo */
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
        public array $appliesTo,
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
            appliesTo: self::appliesTo($tax),
            postingTransactionCode: $tax->transactionCode->code,
        );
    }

    /**
     * Names of what the tax applies to: "Room revenue" once for all room codes, then charge items.
     *
     * @return list<string>
     */
    private static function appliesTo(Tax $tax): array
    {
        $codes = $tax->applicableTransactionCodes;
        $roomRevenue = $codes->contains(
            fn (TransactionCode $code): bool => in_array($code->system_key, SystemTransactionCode::roomRevenueKeys(), true),
        );
        $otherNames = array_values($codes
            ->reject(fn (TransactionCode $code): bool => in_array($code->system_key, SystemTransactionCode::roomRevenueKeys(), true))
            ->map(fn (TransactionCode $code): string => $code->chargeItem->name ?? $code->name)
            ->sort()
            ->all());

        return $roomRevenue ? [(string) __('Room revenue'), ...$otherNames] : $otherNames;
    }
}
