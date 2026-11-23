<?php

namespace App\Actions\NightAudit;

use App\Enums\AuditExceptionType;
use App\Models\FacilityBooking;
use App\Models\NightAuditException;
use App\Models\User;

class ResolveFacilityBookingExceptions
{
    /**
     * Resolve the open `Facility` exceptions of one booking once it is posted, so the readiness panel stops listing
     * it. `PostFacilityBooking` calls it inside its transaction. A null actor is the night audit. Returns how many were
     * resolved. See docs/33-facilities/04-facility-bookings.md#posting.
     */
    public function __invoke(?User $actor, FacilityBooking $booking): int
    {
        return NightAuditException::query()
            ->where('property_id', $booking->property_id)
            ->where('type', AuditExceptionType::Facility->value)
            ->where('facility_booking_id', $booking->id)
            ->open()
            ->update(['resolved_at' => now(), 'resolved_by' => $actor?->id, 'updated_at' => now()]);
    }
}
