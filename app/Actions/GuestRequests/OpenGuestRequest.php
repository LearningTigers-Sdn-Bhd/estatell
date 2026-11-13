<?php

namespace App\Actions\GuestRequests;

use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\UnitStatus;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\GuestRequests\GuestRequestDay;

class OpenGuestRequest
{
    public function __construct(private GuestRequestDay $day) {}

    /**
     * Open a request for a stay, a room or both. Housekeeping and Maintenance need a CheckedIn stay; a Complaint or
     * Other also accepts a CheckedOut one. The room defaults to the room the stay sleeps in on the business date, and a
     * room the stay never uses is `room_mismatch`. `$actor` is null when the guest's own page opens it. A request of
     * another property's stay or room is refused like a missing one. Nothing is written for a refusal.
     */
    public function __invoke(?User $actor, Property $property, GuestRequestType $type, GuestRequestSource $source, string $details, ?ReservationUnit $stay, ?int $roomId): GuestRequestResult
    {
        if ($stay === null && $roomId === null) {
            return GuestRequestResult::refused(GuestRequestRefusal::NoTarget);
        }

        $date = $this->day->for($property);

        if ($stay !== null) {
            if ($stay->property_id !== $property->id) {
                return GuestRequestResult::refused(GuestRequestRefusal::NoTarget);
            }

            $allowed = $type->needsInHouseStay() ? [UnitStatus::CheckedIn] : [UnitStatus::CheckedIn, UnitStatus::CheckedOut];

            if (! in_array($stay->status, $allowed, true)) {
                return GuestRequestResult::refused(GuestRequestRefusal::StayNotInHouse);
            }

            $stayRooms = UnitNight::query()->where('reservation_unit_id', $stay->id)->whereNotNull('room_id')->pluck('room_id');

            if ($roomId !== null && ! $stayRooms->contains($roomId)) {
                return GuestRequestResult::refused(GuestRequestRefusal::RoomMismatch);
            }

            $roomId ??= UnitNight::query()->where('reservation_unit_id', $stay->id)->whereNotNull('room_id')->where('date', '<=', $date->toDateString())->orderByDesc('date')->value('room_id')
                ?? UnitNight::query()->where('reservation_unit_id', $stay->id)->whereNotNull('room_id')->orderBy('date')->value('room_id');
        }

        if ($roomId !== null && ! Room::query()->where('property_id', $property->id)->whereKey($roomId)->exists()) {
            return GuestRequestResult::refused(GuestRequestRefusal::RoomMismatch);
        }

        $request = GuestRequest::query()->create([
            'property_id' => $property->id,
            'reservation_unit_id' => $stay?->id,
            'room_id' => $roomId,
            'type' => $type,
            'source' => $source,
            'details' => trim($details),
            'status' => GuestRequestStatus::Open,
            'opened_at' => now(),
            'opened_business_date' => $date->toDateString(),
            'opened_by' => $actor?->id,
        ]);

        activity()
            ->performedOn($request)
            ->causedBy($actor)
            ->event('guest_request.opened')
            ->withProperties(['property_id' => $property->id, 'type' => $type->value, 'source' => $source->value])
            ->log('Guest request opened');

        return GuestRequestResult::done($request);
    }
}
