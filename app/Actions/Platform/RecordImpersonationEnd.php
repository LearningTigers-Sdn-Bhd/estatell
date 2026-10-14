<?php

namespace App\Actions\Platform;

use App\Enums\ImpersonationReason;
use App\Models\Property;
use App\Notifications\SupportAccessUsed;
use App\Services\Platform\Impersonation;
use App\Services\Staff\PropertyOwners;

class RecordImpersonationEnd
{
    public function __construct(private Impersonation $impersonation, private PropertyOwners $owners) {}

    /**
     * Log `impersonation.ended` with the duration and tell the property's owner, while the session is still open.
     * It changes nothing else: ending the session is up to the caller. Does nothing when no session is open.
     */
    public function __invoke(): void
    {
        $details = $this->impersonation->logProperties();
        $startedAt = $this->impersonation->startedAt();
        $property = Property::query()->find($details['property_id'] ?? null);

        if ($details === [] || $startedAt === null || $property === null) {
            return;
        }

        $minutes = $this->impersonation->elapsedMinutes();

        activity()
            ->performedOn($property)
            ->causedBy($this->impersonation->supportUser())
            ->event('impersonation.ended')
            ->withProperties([...$details, 'duration_minutes' => $minutes])
            ->log('Support session ended');

        $owner = $this->owners->firstFor($property);

        if ($owner === null || $owner->deactivated_at !== null) {
            return;
        }

        $owner->notify(new SupportAccessUsed(
            supportUserName: (string) $details['support_user_name'],
            partnerName: $details['partner_name'],
            reason: ImpersonationReason::from((string) $details['reason'])->label(),
            note: $details['note'],
            startedAt: $startedAt,
            minutes: $minutes,
        ));
    }
}
