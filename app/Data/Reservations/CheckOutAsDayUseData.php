<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

class CheckOutAsDayUseData extends Data
{
    public function __construct(
        public ?string $refusal = null,
        public ?string $date = null,
        public ?string $start = null,
        public ?string $end = null,
        public ?string $blockEnd = null,
        public ?int $hours = null,
        public ?int $ratePlanId = null,
        public ?string $planPrice = null,
        public ?string $amount = null,
        public ?string $overrideAmount = null,
        public bool $midnightLimited = false,
        public ?UnitCheckOutPreviewData $settlement = null,
        /** @var list<UnitPendingLineData> */
        public array $lines = [],
        /** @var list<UnitPendingLineData> */
        public array $folioLines = [],
        public ?string $ratePlanName = null,
        public ?string $perNightPlanName = null,
        public ?string $planNote = null,
        /** @var list<DayUsePlanOptionData> */
        public array $planOptions = [],
        public ?string $checkedInTime = null,
        public bool $noTaxes = false,
        /** @var list<string> */
        public array $droppedAddOns = [],
        /** @var array<string, string> */
        public array $errors = [],
    ) {}
}
