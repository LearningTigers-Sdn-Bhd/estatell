<?php

namespace App\Actions\Platform;

use App\Models\Property;
use App\Services\Platform\Impersonation;

class RecordImpersonationEnd
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Log `impersonation.ended` with the duration, while the session is still open. It changes nothing else: ending
     * the session is up to the caller. Does nothing when no session is open.
     */
    public function __invoke(): void
    {
        $details = $this->impersonation->logProperties();
        $property = Property::query()->find($details['property_id'] ?? null);

        if ($details === [] || $this->impersonation->startedAt() === null || $property === null) {
            return;
        }

        activity()
            ->performedOn($property)
            ->causedBy($this->impersonation->supportUser())
            ->event('impersonation.ended')
            ->withProperties([...$details, 'duration_minutes' => $this->impersonation->elapsedMinutes()])
            ->log('Support session ended');
    }
}
