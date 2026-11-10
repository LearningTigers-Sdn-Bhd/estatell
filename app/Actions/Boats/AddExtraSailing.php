<?php

namespace App\Actions\Boats;

use App\Data\Boats\SailingData;
use App\Enums\SailingKind;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\SailingChoices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AddExtraSailing
{
    public function __construct(
        private SailingChoices $choices,
        private BoatTripUsage $boatTripUsage,
        private SyncBoatSailingCrew $syncCrew,
    ) {}

    /**
     * Add an extra guest trip on a date: a sailing that is not on any departure. It needs a direction and both times
     * (Arrives after Leaves, `times_invalid`), and the pick-up point, boat and crew are optional. It has no fare, and its
     * seats are the boat's seats, else none. A date before the business date is read only (`date_closed`). Logs
     * `boat_sailing.extra_added`. See docs/24-boats/04-sailings.md.
     */
    public function __invoke(User $actor, Property $property, CarbonImmutable $date, SailingData $data): SailingResult
    {
        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        $leavesAt = $data->leavesAt === null ? null : substr($data->leavesAt, 0, 5);
        $arrivesAt = $data->arrivesAt === null ? null : substr($data->arrivesAt, 0, 5);

        if ($data->direction === null || $leavesAt === null || $arrivesAt === null || $arrivesAt <= $leavesAt) {
            return SailingResult::refused(SailingResult::REFUSAL_TIMES_INVALID);
        }

        $assistants = $data->assistants();
        $refusal = $this->choices->refusal($property->id, $data->boatId, $data->inChargeId, $assistants);

        if ($refusal !== null) {
            return SailingResult::refused($refusal);
        }

        return DB::transaction(function () use ($actor, $property, $date, $data, $leavesAt, $arrivesAt, $assistants): SailingResult {
            $sailing = BoatSailing::query()->create([
                'property_id' => $property->id,
                'date' => $date->toDateString(),
                'kind' => SailingKind::Guests,
                'direction' => $data->direction->value,
                'leaves_at' => $leavesAt.':00',
                'arrives_at' => $arrivesAt.':00',
                'pickup_point' => filled($data->pickupPoint) ? trim((string) $data->pickupPoint) : null,
                'boat_id' => $data->boatId,
            ]);

            ($this->syncCrew)($sailing, $data->inChargeId, $assistants);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.extra_added')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $sailing->id,
                    'date' => $date->toDateString(),
                    'direction' => $data->direction->value,
                    'leaves_at' => $leavesAt,
                    'arrives_at' => $arrivesAt,
                    'boat_id' => $data->boatId,
                    'in_charge' => $data->inChargeId,
                    'assistants' => $assistants,
                ])
                ->log('Extra boat trip added');

            return SailingResult::done($sailing);
        });
    }
}
