<?php

namespace App\Actions\Boats;

use App\Enums\SailingKind;
use App\Enums\UnitStatus;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CancelDepartureDate
{
    public function __construct(private BoatTripUsage $boatTripUsage, private StoreMainSailing $storeMainSailing) {}

    /**
     * Cancel a departure on one date, or undo it (`$cancelled` false). Cancelling sets `cancelled_at` and `cancelled_by` on
     * all the departure's sailings of the date, storing the main sailing first when the date has no rows. It is refused
     * while any trip of the departure is on that date (`sailing_has_guests`, with the guest count), whichever sailing the
     * guests are on, and for a date before the business date (`date_closed`). A cancel or undo that changes nothing writes
     * and logs nothing. Locks the departure, then the date's sailings. Logs `boat_sailing.cancelled` or
     * `boat_sailing.restored`. See docs/24-boats/04-sailings.md#cancel.
     */
    public function __invoke(User $actor, BoatDeparture $departure, CarbonImmutable $date, bool $cancelled = true): SailingResult
    {
        $property = Property::query()->findOrFail($departure->property_id);

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        return DB::transaction(function () use ($actor, $property, $departure, $date, $cancelled): SailingResult {
            $locked = BoatDeparture::query()->where('property_id', $property->id)->lockForUpdate()->findOrFail($departure->id);
            $rows = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('boat_departure_id', $locked->id)
                ->whereDate('date', $date->toDateString())
                ->where('kind', SailingKind::Guests->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($cancelled) {
                $guests = $this->guests($locked, $date);

                if ($guests > 0) {
                    return SailingResult::refused(SailingResult::REFUSAL_SAILING_HAS_GUESTS, $guests);
                }

                if ($rows->isEmpty()) {
                    $rows = collect([($this->storeMainSailing)($locked, $date)]);
                }

                $changing = $rows->filter(fn (BoatSailing $sailing): bool => $sailing->cancelled_at === null);
            } else {
                $changing = $rows->filter(fn (BoatSailing $sailing): bool => $sailing->cancelled_at !== null);
            }

            if ($changing->isEmpty()) {
                return SailingResult::done($rows->first());
            }

            BoatSailing::query()->whereKey($changing->pluck('id')->all())->update($cancelled
                ? ['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'updated_at' => now()]
                : ['cancelled_at' => null, 'cancelled_by' => null, 'updated_at' => now()]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event($cancelled ? 'boat_sailing.cancelled' : 'boat_sailing.restored')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_departure_id' => $locked->id,
                    'date' => $date->toDateString(),
                    'boat_sailing_ids' => $changing->pluck('id')->all(),
                ])
                ->log($cancelled ? 'Boat departure cancelled for a date' : 'Boat departure restored for a date');

            return SailingResult::done($rows->first());
        });
    }

    /** The guests of every trip booked on the departure on the date, on any of its sailings or on an extra trip. */
    private function guests(BoatDeparture $departure, CarbonImmutable $date): int
    {
        return (int) DB::table('unit_boat_trips')
            ->join('reservation_units', 'reservation_units.id', '=', 'unit_boat_trips.reservation_unit_id')
            ->where('unit_boat_trips.boat_departure_id', $departure->id)
            ->where('unit_boat_trips.direction', $departure->direction->value)
            ->whereDate("reservation_units.{$departure->direction->tripDateColumn()}", $date->toDateString())
            ->whereIn('reservation_units.status', array_map(fn (UnitStatus $status): string => $status->value, UnitStatus::holdingInventory()))
            ->sum(DB::raw('reservation_units.adults + reservation_units.children'));
    }
}
