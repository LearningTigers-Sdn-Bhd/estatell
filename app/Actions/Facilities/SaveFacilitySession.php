<?php

namespace App\Actions\Facilities;

use App\Models\Facility;
use App\Models\FacilitySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveFacilitySession
{
    /**
     * Add a session to a facility, or change one (`$session`). Saved bookings keep their own times.
     * Logs `facility_session.created` or `facility_session.updated`.
     *
     * @param  array{starts_at: string, ends_at: string}  $attributes  Times as `HH:MM`.
     */
    public function __invoke(User $actor, Facility $facility, array $attributes, ?FacilitySession $session = null): FacilitySession
    {
        return DB::transaction(function () use ($actor, $facility, $attributes, $session): FacilitySession {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);

            $session ??= new FacilitySession;
            $session->fill($attributes);
            $session->property_id = $facility->property_id;
            $session->facility_id = $facility->id;
            $event = $session->exists ? 'facility_session.updated' : 'facility_session.created';
            $session->save();

            activity()
                ->performedOn($session)
                ->causedBy($actor)
                ->event($event)
                ->withProperties(['property_id' => $facility->property_id, 'facility_id' => $facility->id, 'starts_at' => $attributes['starts_at'], 'ends_at' => $attributes['ends_at']])
                ->log($event === 'facility_session.created' ? 'Facility session added' : 'Facility session updated');

            return $session;
        });
    }
}
