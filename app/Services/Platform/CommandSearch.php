<?php

namespace App\Services\Platform;

use App\Data\Navigation\CommandResultData;
use App\Enums\PartnerStatus;
use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\User;
use App\Services\Guests\GuestCommandSearch;
use App\Services\Reservations\ReservationCommandSearch;
use App\Services\Rooms\RoomCommandSearch;
use Illuminate\Support\Facades\Gate;

class CommandSearch
{
    public function __construct(
        private PropertyCommandSearch $properties,
        private GuestCommandSearch $guests,
        private RoomCommandSearch $rooms,
        private ReservationCommandSearch $reservations,
    ) {}

    /** @return list<CommandResultData> */
    public function forUser(User $user, string $text): array
    {
        if ($user->is_admin || $user->partner_id !== null) {
            abort_unless($user->is_admin || $user->partner?->status === PartnerStatus::Active, 404);
            $results = $this->properties->search($user, $text);
            $property = $this->properties->forReference($user, $text);

            if ($property !== null && Gate::forUser($user)->allows('viewAs', $property)) {
                $results = [...$results, ...$this->reservations->search($property, $text, support: true)];
            }

            return $results;
        }

        $property = $user->property;
        abort_unless($property !== null, 404);

        if ($property->status !== PropertyStatus::Live) {
            return [];
        }

        setPermissionsTeamId($property->id);
        $canViewGuests = $user->can(PermissionEnum::GuestsView->value);
        $canViewRooms = $user->can(PermissionEnum::RoomsView->value);

        return [
            ...($canViewGuests ? $this->guests->search($property, $text) : []),
            ...($canViewRooms ? $this->rooms->search($property, $text) : []),
            ...($user->can(PermissionEnum::ReservationsView->value) ? $this->reservations->search($property, $text, canViewGuests: $canViewGuests, canViewRooms: $canViewRooms) : []),
        ];
    }
}
