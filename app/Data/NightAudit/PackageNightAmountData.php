<?php

namespace App\Data\NightAudit;

use App\Models\UnitNight;
use Brick\Money\Money;
use Spatie\LaravelData\Data;

class PackageNightAmountData extends Data
{
    public function __construct(public int $id, public string $date, public string $oldAmount, public ?string $newAmount, public bool $charged = false) {}

    public static function fromNight(UnitNight $night, ?Money $amount = null, bool $charged = false): self
    {
        return new self($night->id, $night->date->toDateString(), $night->amount, $amount === null ? null : (string) $amount->getAmount(), $charged);
    }
}
