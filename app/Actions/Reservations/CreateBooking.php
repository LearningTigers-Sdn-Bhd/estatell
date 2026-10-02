<?php

namespace App\Actions\Reservations;

use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\UpdateGuest;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewReservationData;
use App\Data\Reservations\UnitPickData;
use App\Models\Bed;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CreateBooking
{
    public function __construct(
        private CreateGuest $createGuest,
        private UpdateGuest $updateGuest,
        private CreateReservation $createReservation,
        private AttachGuest $attachGuest,
        private AssignRoom $assignRoom,
        private AssignBed $assignBed,
    ) {}

    /**
     * Save what the booking form collected, in one transaction, by running the other Actions in this order: the guest
     * tabs (`CreateGuest` or `UpdateGuest`), `CreateReservation` with its lines, `AttachGuest` for each guest who stays,
     * then `AssignRoom` or `AssignBed` for each pick. It holds no rules of its own. When any step is refused,
     * everything rolls back, including new guests and the reference number, and the result names the step.
     * `$fromChannel` is passed to `CreateReservation`. Permissions for creating guests are checked by the caller.
     * See docs/12-reservations/05-booking-form.md.
     */
    public function __invoke(User $actor, Property $property, NewBookingData $data, bool $fromChannel = false): BookingResult
    {
        if ($data->guests === []) {
            return BookingResult::refused(BookingResult::REFUSAL_BOOKER_REQUIRED);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $fromChannel): BookingResult {
                $guestIds = [];

                foreach ($data->guests as $index => $tab) {
                    $guestIds[$index] = $this->resolveGuest($actor, $property, $tab, $index);
                }

                $result = ($this->createReservation)($actor, $property, new NewReservationData(
                    bookerGuestId: $guestIds[0],
                    source: $data->source,
                    units: $data->units,
                    billingMode: $data->billingMode,
                    channelName: $data->channelName,
                    externalReference: $data->externalReference,
                    groupName: $data->groupName,
                    notes: $data->notes,
                ), $fromChannel);

                if ($result->isRefused()) {
                    throw new BookingRefused(BookingResult::refused((string) $result->refusal, lineIndex: $result->lineIndex, date: $result->date));
                }

                $reservation = $result->reservation;
                $units = $reservation->units()->orderBy('id')->get()->values();

                $this->attachGuests($actor, $data, $guestIds, $units);
                $this->assignPicks($actor, $property, $data, $units);

                return BookingResult::created($reservation);
            });
        } catch (BookingRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * @throws BookingRefused
     */
    private function resolveGuest(User $actor, Property $property, NewBookingGuestData $tab, int $index): int
    {
        if ($tab->guestId === null) {
            if (trim((string) ($tab->profile['full_name'] ?? '')) === '') {
                throw new BookingRefused(BookingResult::refused(BookingResult::REFUSAL_GUEST_NAME_REQUIRED, guestIndex: $index));
            }

            return ($this->createGuest)($actor, $property, $tab->profile)->id;
        }

        if ($tab->updateProfile && $tab->profile !== []) {
            $guest = Guest::query()->where('property_id', $property->id)->find($tab->guestId);

            if ($guest === null || ! ($this->updateGuest)($actor, $guest, $tab->profile)) {
                throw new BookingRefused(BookingResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND, guestIndex: $index));
            }
        }

        return $tab->guestId;
    }

    /**
     * @param  array<int, int>  $guestIds
     * @param  Collection<int, ReservationUnit>  $units
     *
     * @throws BookingRefused
     */
    private function attachGuests(User $actor, NewBookingData $data, array $guestIds, Collection $units): void
    {
        foreach ($data->guests as $index => $tab) {
            if ($tab->unitIndex === null) {
                continue;
            }

            $unit = $units->get($tab->unitIndex);

            if ($unit === null) {
                throw new BookingRefused(BookingResult::refused(BookingResult::REFUSAL_UNIT_NOT_FOUND, guestIndex: $index, unitIndex: $tab->unitIndex));
            }

            $result = ($this->attachGuest)($actor, $unit, Guest::query()->findOrFail($guestIds[$index]));

            if ($result->isRefused()) {
                throw new BookingRefused(BookingResult::refused((string) $result->refusal, guestIndex: $index, unitIndex: $tab->unitIndex));
            }
        }
    }

    /**
     * @param  Collection<int, ReservationUnit>  $units
     *
     * @throws BookingRefused
     */
    private function assignPicks(User $actor, Property $property, NewBookingData $data, Collection $units): void
    {
        foreach ($data->picks as $pick) {
            if ($pick->roomId === null && $pick->bedId === null) {
                continue;
            }

            $unit = $units->get($pick->unitIndex);

            if ($unit === null) {
                throw new BookingRefused(BookingResult::refused(BookingResult::REFUSAL_UNIT_NOT_FOUND, unitIndex: $pick->unitIndex));
            }

            $result = $this->assign($actor, $property, $unit, $pick);

            if ($result->isRefused()) {
                throw new BookingRefused(BookingResult::refused((string) $result->refusal, unitIndex: $pick->unitIndex, date: $result->date));
            }
        }
    }

    private function assign(User $actor, Property $property, ReservationUnit $unit, UnitPickData $pick): AssignmentResult
    {
        if ($pick->roomId !== null) {
            $room = Room::query()->where('property_id', $property->id)->find($pick->roomId);

            return $room === null
                ? AssignmentResult::refused(AssignmentResult::REFUSAL_ROOM_NOT_FOUND)
                : ($this->assignRoom)($actor, $unit, $room);
        }

        $bed = Bed::query()->whereHas('room', fn ($rooms) => $rooms->where('property_id', $property->id))->find($pick->bedId);

        return $bed === null
            ? AssignmentResult::refused(AssignmentResult::REFUSAL_BED_NOT_FOUND)
            : ($this->assignBed)($actor, $unit, $bed);
    }
}
