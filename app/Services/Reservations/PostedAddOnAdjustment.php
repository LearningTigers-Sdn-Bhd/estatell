<?php

namespace App\Services\Reservations;

use App\Models\ChargeItem;
use App\Models\FolioLine;

/**
 * One charged per-guest add-on that Change guests corrects, from `PostedAddOnAdjustments`. `key` is
 * "{charge item id}|{service date}", the value the form sends to skip it. `reverseLines` are reversed first (empty when
 * guests are only added), then `newRows` are added and charged on the business date.
 */
final readonly class PostedAddOnAdjustment
{
    /**
     * @param  list<FolioLine>  $reverseLines
     * @param  list<array{quantity: int, age_group_id: int|null, service_date: string, unit_amount: string}>  $newRows
     */
    public function __construct(
        public string $key,
        public ChargeItem $item,
        public array $reverseLines,
        public array $newRows,
        public string $reverseAmount,
        public string $chargeAmount,
        public string $summary,
    ) {}

    public function needsReversal(): bool
    {
        return $this->reverseLines !== [];
    }
}
