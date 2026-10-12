<?php

namespace App\Data\NightAudit;

use App\Enums\Currency;
use App\Models\NightAuditException;
use App\Models\UnitNight;
use App\Services\NightAudit\PackageNightRespreadResult;
use Spatie\LaravelData\Data;

class PackageNightRespreadData extends Data
{
    /**
     * @param  list<PackageNightAmountData>  $nights
     * @param  list<PackageNightAmountData>  $chargedNights
     */
    public function __construct(
        public string $title,
        public Currency $currency,
        public string $total,
        public string $retryNight,
        public array $nights,
        public array $chargedNights,
    ) {}

    public static function fromSpread(NightAuditException $exception, PackageNightRespreadResult $spread): self
    {
        $unit = $exception->reservationUnit;
        $target = $unit->nights()->with(['room', 'bed.room', 'ratePlan'])->whereDate('date', $exception->nightAudit->businessDate->date)->firstOrFail();
        $room = $target->room?->number ?? $target->bed?->room?->number;

        return new self(
            title: __('Fix package price').' · '.($room === null ? $unit->roomType->name : __('Room :room', ['room' => $room])).' · '.$target->ratePlan->name,
            currency: $unit->property->settings->currency,
            total: (string) $spread->total->getAmount(),
            retryNight: $target->date->toDateString(),
            nights: array_map(fn (UnitNight $night, int $index): PackageNightAmountData => PackageNightAmountData::fromNight($night, $spread->amounts[$index]), $spread->nights, array_keys($spread->nights)),
            chargedNights: array_map(fn (UnitNight $night): PackageNightAmountData => PackageNightAmountData::fromNight($night), $spread->chargedNights),
        );
    }
}
