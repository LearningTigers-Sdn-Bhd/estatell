<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Models\GuestRequest;

/**
 * What a guest request Action returns: the request, or the refusal that stopped it. Nothing is written for a refusal.
 */
final readonly class GuestRequestResult
{
    private function __construct(public ?GuestRequest $request, public ?GuestRequestRefusal $refusal) {}

    public static function done(GuestRequest $request): self
    {
        return new self($request, null);
    }

    public static function refused(GuestRequestRefusal $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
