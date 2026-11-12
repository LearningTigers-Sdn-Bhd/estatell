<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A folio was closed, by any path: the folio tab, check-out, group check-out, the kiosk or an old stay. E-invoicing
 * listens to send the stay's e-invoice by itself when the hotel asked for that. `actorId` is null for a system close.
 */
class FolioClosed
{
    use Dispatchable;

    public function __construct(public readonly int $propertyId, public readonly int $folioId, public readonly ?int $actorId) {}
}
