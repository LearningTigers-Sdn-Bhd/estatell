<?php

namespace App\Actions\Kiosk;

use App\Models\KioskWalkInHold;
use Brick\Money\Money;

final readonly class KioskWalkInResult
{
    public function __construct(public ?KioskWalkInHold $hold = null, public ?Money $deposit = null, public ?string $refusal = null) {}
}
