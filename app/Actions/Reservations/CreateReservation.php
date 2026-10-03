<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\SyncRoutingRules;
use App\Data\Reservations\NewReservationData;
use App\Data\Reservations\NewUnitData;
use App\Enums\DocumentType;
use App\Enums\ReservationStatus;
use App\Enums\SellUnit;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Support\Facades\DB;

class CreateReservation
{
    public function __construct(private AddUnit $addUnit, private NextDocumentNumber $nextDocumentNumber, private SyncRoutingRules $syncRoutingRules) {}

    /**
     * Create a reservation and its units in one transaction.
     *
     * A Bed room type line adds one unit for each bed. When any line is refused, nothing is saved, the reference
     * number is not used up, nothing is logged, and the result names the line. `$fromChannel` is passed to `AddUnit`. `SyncRoutingRules` runs when the units are in, so a company or a group reservation gets its Master folio and rules. See docs/12-reservations/02-group-bookings.md.
     */
    public function __invoke(User $actor, Property $property, NewReservationData $data, bool $fromChannel = false): ReservationResult
    {
        if ($data->units === []) {
            return ReservationResult::refused(ReservationResult::REFUSAL_UNITS_REQUIRED);
        }

        if (! Guest::query()->where('property_id', $property->id)->notMerged()->whereNull('anonymized_at')->whereKey($data->bookerGuestId)->exists()) {
            return ReservationResult::refused(ReservationResult::REFUSAL_BOOKER_NOT_FOUND);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $fromChannel): ReservationResult {
                $roomTypes = RoomType::query()
                    ->where('property_id', $property->id)
                    ->whereIn('id', array_unique(array_map(fn (NewUnitData $line): int => $line->roomTypeId, $data->units)))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $reservation = new Reservation([
                    'booker_guest_id' => $data->bookerGuestId,
                    'company_id' => $data->companyId,
                    'group_name' => $data->groupName,
                    'billing_mode' => $data->billingMode,
                    'source' => $data->source,
                    'channel_name' => $data->channelName,
                    'external_reference' => $data->externalReference,
                    'notes' => $data->notes === null || trim($data->notes) === '' ? null : trim($data->notes),
                ]);
                $reservation->forceFill([
                    'property_id' => $property->id,
                    'reference' => ($this->nextDocumentNumber)($property, DocumentType::Reservation)->reference,
                    'status' => ReservationStatus::Confirmed,
                    'created_by' => $actor->id,
                ])->save();

                foreach ($data->units as $index => $line) {
                    foreach (range(1, $this->unitCount($roomTypes->get($line->roomTypeId), $line, $index)) as $ignored) {
                        $result = ($this->addUnit)($actor, $reservation, $line, $index, $fromChannel);

                        if ($result->isRefused()) {
                            throw new ReservationRefused($result);
                        }
                    }
                }

                ($this->syncRoutingRules)($reservation);

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.created')
                    ->withProperties([
                        'property_id' => $property->id,
                        'reference' => $reservation->reference,
                        'source' => $reservation->source->value,
                        'units' => $reservation->units()->count(),
                        'has_notes' => $reservation->notes !== null,
                    ])
                    ->log('Reservation created');

                return ReservationResult::created($reservation);
            });
        } catch (ReservationRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * How many units a line adds: one for each bed of a Bed room type, otherwise one. An unknown room type adds one
     * and `AddUnit` refuses it.
     *
     * @throws ReservationRefused when the bed count does not fit the room type
     */
    private function unitCount(?RoomType $roomType, NewUnitData $line, int $index): int
    {
        if ($roomType === null) {
            return 1;
        }

        if ($roomType->sell_unit === SellUnit::Bed) {
            return $line->beds < 1 ? throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_INVALID_STAY, $index)) : $line->beds;
        }

        return $line->beds > 1 ? throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_INVALID_STAY, $index)) : 1;
    }
}
