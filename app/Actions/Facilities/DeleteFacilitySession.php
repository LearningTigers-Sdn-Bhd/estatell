<?php

namespace App\Actions\Facilities;

use App\Models\FacilitySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteFacilitySession
{
    /**
     * Delete one session. Bookings copied its times, so they do not change. Logs `facility_session.deleted`.
     */
    public function __invoke(User $actor, FacilitySession $session): void
    {
        DB::transaction(function () use ($actor, $session): void {
            $session->delete();

            activity()
                ->causedBy($actor)
                ->event('facility_session.deleted')
                ->withProperties(['property_id' => $session->property_id, 'facility_id' => $session->facility_id, 'starts_at' => $session->starts_at, 'ends_at' => $session->ends_at])
                ->log('Facility session deleted');
        });
    }
}
