<?php

namespace App\Actions\Pos;

use App\Enums\PosPinOutcome;
use App\Models\User;

/**
 * The outcome of a POS PIN check, with the verified user when it passed.
 */
class PosPinResult
{
    private function __construct(public readonly PosPinOutcome $outcome, public readonly ?User $user) {}

    public static function verified(User $user): self
    {
        return new self(PosPinOutcome::Verified, $user);
    }

    public static function refused(PosPinOutcome $outcome): self
    {
        return new self($outcome, null);
    }

    public function isVerified(): bool
    {
        return $this->outcome === PosPinOutcome::Verified;
    }
}
