<?php

namespace App\Data\NightAudit;

use App\Data\Property\CurrencyData;
use App\Models\NightAuditException;
use App\Models\UnitNight;
use App\Services\NightAudit\PackageNightRespreadResult;
use Spatie\LaravelData\Data;

class PackageNightRespreadData extends Data
{
    /**
     * @param  list<PackageNightAmountData>  $nights
     * @param  list<PackageNightAmountData>  $chargedNights  Charged nights left as they are
     * @param  bool  $wholeStay  Every night is spread again, and the charged nights in `$nights` are corrected
     * @param  string|null  $blockedReason  Why the user cannot apply this spread; Fix price is disabled
     */
    public function __construct(
        public string $title,
        public CurrencyData $currency,
        public string $total,
        public string $retryNight,
        public array $nights,
        public array $chargedNights,
        public bool $wholeStay,
        public ?string $blockedReason,
    ) {}

    public static function fromSpread(NightAuditException $exception, PackageNightRespreadResult $spread, bool $canCorrectChargedNights): self
    {
        $unit = $exception->reservationUnit;
        $target = $unit->nights()->with(['room', 'bed.room', 'ratePlan'])->whereDate('date', $exception->nightAudit->businessDate->date)->firstOrFail();
        $room = $target->room?->number ?? $target->bed?->room?->number;

        return new self(
            title: __('Fix package price').' · '.($room === null ? $unit->roomType->name : __('Room :room', ['room' => $room])).' · '.$target->ratePlan->name,
            currency: CurrencyData::fromCurrency($unit->property->settings->currency),
            total: (string) $spread->total->getAmount(),
            retryNight: $target->date->toDateString(),
            nights: array_map(fn (UnitNight $night, int $index): PackageNightAmountData => PackageNightAmountData::fromNight($night, $spread->amounts[$index], $spread->isCharged($night)), $spread->nights, array_keys($spread->nights)),
            chargedNights: array_map(fn (UnitNight $night): PackageNightAmountData => PackageNightAmountData::fromNight($night), $spread->chargedNights),
            wholeStay: $spread->wholeStay,
            blockedReason: $spread->wholeStay && ! $canCorrectChargedNights ? __('Correcting charged nights needs the Change price permission.') : null,
        );
    }
}
