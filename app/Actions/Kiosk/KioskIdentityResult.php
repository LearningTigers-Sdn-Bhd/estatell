<?php

namespace App\Actions\Kiosk;

final readonly class KioskIdentityResult
{
    private function __construct(public bool $accepted, public bool $nationalityRequired) {}

    public static function accepted(bool $nationalityRequired): self
    {
        return new self(true, $nationalityRequired);
    }

    public static function refused(): self
    {
        return new self(false, false);
    }
}
