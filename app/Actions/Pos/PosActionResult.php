<?php

namespace App\Actions\Pos;

use Illuminate\Database\Eloquent\Model;

/**
 * What a POS Action returns: the row it saved, or a refusal code when nothing was written. `replayed` is true when the
 * same idempotency key was seen before, so the earlier row comes back and nothing is written twice.
 */
class PosActionResult
{
    private function __construct(public readonly ?Model $model, public readonly ?string $refusal, public readonly bool $replayed = false) {}

    public static function saved(Model $model): self
    {
        return new self($model, null);
    }

    public static function replayed(Model $model): self
    {
        return new self($model, null, true);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
