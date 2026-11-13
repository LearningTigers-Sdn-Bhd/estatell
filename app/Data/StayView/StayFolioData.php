<?php

namespace App\Data\StayView;

use App\Data\Shared\BadgeData;
use App\Enums\Tone;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The money figures of a reservation for the peek sheet, the same as on the reservation card. `total` counts room
 * nights only. `paid` is real payments, `billedToCompany` the DIRECT_BILL line, and `balance` the sum of the folios that
 * are not Voided. `depositHeld` is set when the balance is below 0.
 */
class StayFolioData extends Data
{
    public function __construct(
        public string $total,
        public string $paid,
        public string $billedToCompany,
        public string $balance,
        public ?BadgeData $depositHeld,
    ) {}

    /**
     * @param  array{total: string, paid: string, billed: string, balance: string}  $money
     */
    public static function fromMoney(array $money): self
    {
        return new self(
            total: $money['total'],
            paid: $money['paid'],
            billedToCompany: $money['billed'],
            balance: $money['balance'],
            depositHeld: BigDecimal::of($money['balance'])->isNegative() ? new BadgeData('deposit_held', __('Deposit held'), Tone::Info) : null,
        );
    }
}
