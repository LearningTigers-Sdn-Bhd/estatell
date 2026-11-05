<?php

namespace App\Actions\Kiosk;

final readonly class KioskCheckInResult
{
    private function __construct(public ?string $roomNumber, public ?string $refusal) {}

    public static function done(?string $roomNumber): self
    {
        return new self($roomNumber, null);
    }

    /**
     * @param  string  $code  A kiosk error code: `card_required_for_check_in`, `deposit_not_paid`, `balance_not_settled`,
     *                        `nationality_required`, `arrival_not_reached` or `server_error`.
     */
    public static function refused(string $code): self
    {
        return new self(null, $code);
    }
}
