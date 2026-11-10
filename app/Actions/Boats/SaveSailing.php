<?php

namespace App\Actions\Boats;

use App\Data\Boats\SailingData;
use App\Enums\SailingKind;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use App\Services\Boats\SailingChoices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveSailing
{
    public function __construct(
        private DateSailings $dateSailings,
        private SailingChoices $choices,
        private BoatTripUsage $boatTripUsage,
        private StoreMainSailing $storeMainSailing,
        private SyncBoatSailingCrew $syncCrew,
        private SetSailingUnitTimes $setUnitTimes,
    ) {}

    /**
     * Set the boat, crew and time of a sailing of a departure on a date. With no `$sailing` it is the main sailing,
     * which is stored first when the date has no rows, copying the departure's default boat and crew. A save that
     * changes nothing writes and logs nothing, and does not store the main sailing. When the property-side time of the
     * sailing changes (`arrives_at` for Boat in, `leaves_at` for Boat out), the units of its trips take it as their
     * `arrival_time` or `departure_time`, in the same transaction.
     *
     * Lock order is the departure, then the date's sailings of the departure, then the units (in id order), the same as
     * `UpdateBoatDeparture` and `SetBoatTrip`. A date before the business date is read only (`date_closed`). See
     * docs/24-boats/04-sailings.md.
     */
    public function __invoke(User $actor, BoatDeparture $departure, CarbonImmutable $date, SailingData $data, ?BoatSailing $sailing = null): SailingResult
    {
        $property = Property::query()->findOrFail($departure->property_id);

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        return DB::transaction(function () use ($actor, $property, $departure, $date, $data, $sailing): SailingResult {
            $locked = BoatDeparture::query()->where('property_id', $property->id)->lockForUpdate()->findOrFail($departure->id);
            $rows = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('boat_departure_id', $locked->id)
                ->whereDate('date', $date->toDateString())
                ->where('kind', SailingKind::Guests->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $stored = $sailing === null ? $rows->first() : $rows->firstWhere('id', $sailing->id);

            if ($sailing !== null && $stored === null) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
            }

            $before = $this->find($property, $date, $locked, $stored);
            $assistants = $data->assistants();
            $refusal = $this->choices->refusal(
                $property->id,
                $data->boatId,
                $data->inChargeId,
                $assistants,
                $before->boat?->id,
                array_values($before->crew->map(fn (BoatCrewMember $member): int => $member->id)->all()),
            );

            if ($refusal !== null) {
                return SailingResult::refused($refusal);
            }

            $times = $this->times($locked, $before, $data);

            if ($times === null) {
                return SailingResult::refused(SailingResult::REFUSAL_TIMES_INVALID);
            }

            $beforeState = $this->state($before);
            $afterState = [
                'boat_id' => $data->boatId,
                'in_charge' => $data->inChargeId,
                'assistants' => $assistants,
                'leaves_at' => array_key_exists('leaves_at', $times) ? $times['leaves_at'] : $beforeState['leaves_at'],
                'arrives_at' => array_key_exists('arrives_at', $times) ? $times['arrives_at'] : $beforeState['arrives_at'],
            ];

            if ($afterState === $beforeState) {
                return SailingResult::done($stored);
            }

            $stored ??= ($this->storeMainSailing)($locked, $date);

            $stored->boat_id = $data->boatId;
            $stored->fill($times);
            $stored->save();

            if ($afterState['in_charge'] !== $beforeState['in_charge'] || $afterState['assistants'] !== $beforeState['assistants']) {
                ($this->syncCrew)($stored, $data->inChargeId, $assistants);
            }

            $unitsChanged = $this->followTime($stored, $property, $locked, $date, $before->propertyTime());

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_sailing.saved')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $stored->id,
                    'boat_departure_id' => $locked->id,
                    'date' => $date->toDateString(),
                    'before' => $beforeState,
                    'after' => $afterState,
                    'units_changed' => $unitsChanged,
                ])
                ->log('Boat sailing saved');

            return SailingResult::done($stored);
        });
    }

    /** The sailing as the pages read it before the save: the stored row, else the main sailing built from the timetable. */
    private function find(Property $property, CarbonImmutable $date, BoatDeparture $departure, ?BoatSailing $stored): DateSailing
    {
        $list = $this->dateSailings->forDate($property, $date, [$departure->id], true)[$departure->id];

        return collect($list)->first(fn (DateSailing $sailing): bool => $sailing->sailing?->id === $stored?->id);
    }

    /**
     * The stored time columns to write: `leaves_at` and `arrives_at`, each null when it matches the timetable. An empty
     * array when the save does not set times. Null when the effective times are not in order.
     *
     * @return array<string, string|null>|null
     */
    private function times(BoatDeparture $departure, DateSailing $before, SailingData $data): ?array
    {
        if (! $data->setsTimes) {
            return [];
        }

        $leavesAt = $data->leavesAt === null ? substr($departure->leaves_at, 0, 5) : substr($data->leavesAt, 0, 5);
        $arrivesAt = $data->arrivesAt === null ? substr($departure->arrives_at, 0, 5) : substr($data->arrivesAt, 0, 5);

        if ($arrivesAt <= $leavesAt) {
            return null;
        }

        return [
            'leaves_at' => $leavesAt === substr($departure->leaves_at, 0, 5) ? null : $leavesAt.':00',
            'arrives_at' => $arrivesAt === substr($departure->arrives_at, 0, 5) ? null : $arrivesAt.':00',
        ];
    }

    /** @return array{boat_id: int|null, in_charge: int|null, assistants: list<int>, leaves_at: string|null, arrives_at: string|null} */
    private function state(DateSailing $sailing): array
    {
        return [
            'boat_id' => $sailing->boat?->id,
            'in_charge' => $sailing->inCharge()?->id,
            'assistants' => array_values($sailing->assistants()->map(fn (BoatCrewMember $member): int => $member->id)->sort()->all()),
            'leaves_at' => $sailing->sailing?->leaves_at === null ? null : substr($sailing->sailing->leaves_at, 0, 5).':00',
            'arrives_at' => $sailing->sailing?->arrives_at === null ? null : substr($sailing->sailing->arrives_at, 0, 5).':00',
        ];
    }

    /**
     * Move the unit times of the sailing's trips when its property-side time changed. Returns how many units changed.
     */
    private function followTime(BoatSailing $stored, Property $property, BoatDeparture $departure, CarbonImmutable $date, string $oldTime): int
    {
        $after = collect($this->dateSailings->forDate($property, $date, [$departure->id], true)[$departure->id])
            ->first(fn (DateSailing $sailing): bool => $sailing->sailing?->id === $stored->id);

        if ($after->propertyTime() === $oldTime) {
            return 0;
        }

        $units = ReservationUnit::query()->whereIn('id', $this->dateSailings->tripsOn($after)->select('unit_boat_trips.reservation_unit_id'));

        return count(($this->setUnitTimes)($units, $departure->direction, $after->propertyTime()));
    }
}
