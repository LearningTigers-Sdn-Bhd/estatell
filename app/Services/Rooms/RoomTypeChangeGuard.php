<?php

namespace App\Services\Rooms;

use App\Enums\SellUnit;
use App\Models\Room;
use App\Models\RoomType;

/**
 * The guards that stop a room taking another room type. Read only.
 *
 * A room with beds cannot move into a type sold by room. A room, or one of its beds, with stays assigned on or after
 * the business date cannot change type. The target type being active is checked by the caller, since it is about
 * the type, not the room. See docs/05-rooms/02-rooms-and-groups.md.
 */
class RoomTypeChangeGuard
{
    public function __construct(private RoomAssignedNights $assignedNights) {}

    /**
     * The message that stops the room taking the room type, or null when it may.
     *
     * The short form reads "301 has beds." or "305 has stays assigned from 20 Oct.". With `$nextStep` it adds what
     * to do next.
     */
    public function refusal(Room $room, RoomType $roomType, bool $nextStep = false): ?string
    {
        if ($room->room_type_id === $roomType->id) {
            return null;
        }

        if ($roomType->sell_unit === SellUnit::Room && $room->beds()->exists()) {
            $message = __(':number has beds.', ['number' => $room->number]);

            return $nextStep ? $message.' '.__('Choose a room type that sells beds.') : $message;
        }

        $firstNight = $this->assignedNights->firstDate($room);

        if ($firstNight === null) {
            return null;
        }

        $message = __(':number has stays assigned from :date.', ['number' => $room->number, 'date' => $firstNight->translatedFormat('j M')]);

        return $nextStep ? $message.' '.__('Move them first.') : $message;
    }
}
