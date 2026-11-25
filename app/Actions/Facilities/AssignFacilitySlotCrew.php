<?php

namespace App\Actions\Facilities;

use App\Enums\FacilityBookingStatus;
use App\Models\Facility;
use App\Models\FacilityBoat;
use App\Models\FacilityBooking;
use App\Models\FacilityGuide;
use App\Models\FacilitySession;
use App\Models\FacilitySlotCrew;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AssignFacilitySlotCrew
{
    public const string REFUSAL_NO_SLOT = 'no_slot';

    public const string REFUSAL_GUIDE_NOT_FOUND = 'guide_not_found';

    public const string REFUSAL_BOAT_NOT_FOUND = 'boat_not_found';

    /**
     * Pick the guide and the boat of one activity slot (a facility on a date from `$startsAt` to `$endsAt`, `H:i`), or
     * none (null). The slot is a session of the facility, or the times of a Tentative or Confirmed booking. A new pick
     * must be an available guide or boat of the facility's property; the current one stays allowed after it is turned
     * off. Both null removes the row. A pick that changes nothing writes and logs nothing. Logs
     * `facility.slot_crew_assigned`. Returns null when done, else the refusal. See
     * docs/33-facilities/06-guides-and-boats.md#activities-run-sheet.
     */
    public function __invoke(User $actor, Facility $facility, CarbonImmutable $date, string $startsAt, string $endsAt, ?int $guideId, ?int $boatId): ?string
    {
        return DB::transaction(function () use ($actor, $facility, $date, $startsAt, $endsAt, $guideId, $boatId): ?string {
            $starts = "{$startsAt}:00";
            $ends = "{$endsAt}:00";
            $isSession = FacilitySession::query()->where('facility_id', $facility->id)->where('starts_at', $starts)->where('ends_at', $ends)->exists();
            $isBooked = FacilityBooking::query()
                ->where('facility_id', $facility->id)
                ->whereDate('booking_date', $date->toDateString())
                ->where('starts_at', $starts)
                ->where('ends_at', $ends)
                ->whereIn('status', [FacilityBookingStatus::Tentative, FacilityBookingStatus::Confirmed])
                ->exists();

            if (! $isSession && ! $isBooked) {
                return self::REFUSAL_NO_SLOT;
            }

            $crew = FacilitySlotCrew::query()
                ->where('facility_id', $facility->id)
                ->whereDate('slot_date', $date->toDateString())
                ->where('starts_at', $starts)
                ->where('ends_at', $ends)
                ->lockForUpdate()
                ->first();

            if ($guideId !== null && $guideId !== $crew?->facility_guide_id
                && ! FacilityGuide::query()->where('property_id', $facility->property_id)->where('active', true)->whereKey($guideId)->exists()) {
                return self::REFUSAL_GUIDE_NOT_FOUND;
            }

            if ($boatId !== null && $boatId !== $crew?->facility_boat_id
                && ! FacilityBoat::query()->where('property_id', $facility->property_id)->where('active', true)->whereKey($boatId)->exists()) {
                return self::REFUSAL_BOAT_NOT_FOUND;
            }

            if ($crew?->facility_guide_id === $guideId && $crew?->facility_boat_id === $boatId) {
                return null;
            }

            if ($guideId === null && $boatId === null) {
                $crew?->delete();
            } elseif ($crew === null) {
                $crew = new FacilitySlotCrew([
                    'facility_id' => $facility->id,
                    'slot_date' => $date->toDateString(),
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'facility_guide_id' => $guideId,
                    'facility_boat_id' => $boatId,
                ]);
                $crew->property_id = $facility->property_id;
                $crew->save();
            } else {
                $crew->update(['facility_guide_id' => $guideId, 'facility_boat_id' => $boatId]);
            }

            activity()
                ->performedOn($facility)
                ->causedBy($actor)
                ->event('facility.slot_crew_assigned')
                ->withProperties([
                    'property_id' => $facility->property_id,
                    'date' => $date->toDateString(),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'facility_guide_id' => $guideId,
                    'facility_boat_id' => $boatId,
                ])
                ->log(__(':facility :date :time guide and boat set.', ['facility' => $facility->name, 'date' => $date->format('j M'), 'time' => "{$startsAt}–{$endsAt}"]));

            return null;
        });
    }
}
