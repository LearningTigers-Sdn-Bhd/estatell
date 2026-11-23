<?php

namespace App\Actions\Facilities;

use App\Enums\FacilitySessionLength;
use App\Models\Facility;
use App\Models\FacilitySession;
use App\Models\User;
use App\Services\Facilities\FacilitySessionSlots;
use Illuminate\Support\Facades\DB;

class AddFacilitySessions
{
    public function __construct(private FacilitySessionSlots $slots) {}

    /**
     * Add the sessions of a range in one transaction: back-to-back sessions of `$length` from `$startsAt` to `$endsAt`,
     * or one session for Whole. Sessions the facility already has are skipped. Logs `facility_session.created` for
     * each new one and returns them.
     *
     * @return list<FacilitySession>
     */
    public function __invoke(User $actor, Facility $facility, string $startsAt, string $endsAt, FacilitySessionLength $length): array
    {
        return DB::transaction(function () use ($actor, $facility, $startsAt, $endsAt, $length): array {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            $existing = $facility->sessions()
                ->get(['starts_at', 'ends_at'])
                ->map(fn (FacilitySession $session): string => substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5))
                ->all();
            $added = [];

            foreach ($this->slots->between($startsAt, $endsAt, $length) as $slot) {
                if (in_array($slot['starts_at'].'-'.$slot['ends_at'], $existing, true)) {
                    continue;
                }

                $session = new FacilitySession($slot);
                $session->property_id = $facility->property_id;
                $session->facility_id = $facility->id;
                $session->save();

                activity()
                    ->performedOn($session)
                    ->causedBy($actor)
                    ->event('facility_session.created')
                    ->withProperties(['property_id' => $facility->property_id, 'facility_id' => $facility->id, ...$slot])
                    ->log('Facility session added');

                $added[] = $session;
            }

            return $added;
        });
    }
}
