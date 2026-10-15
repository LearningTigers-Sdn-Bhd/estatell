<?php

namespace App\Services\Reservations;

use App\Data\Navigation\CommandResultData;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Guests\GuestCommandSearch;
use App\Services\Rooms\RoomCommandSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationCommandSearch
{
    public function __construct(private GuestCommandSearch $guests, private RoomCommandSearch $rooms) {}

    /** @return list<CommandResultData> */
    public function search(Property $property, string $text, bool $support = false, bool $canViewGuests = false, bool $canViewRooms = false): array
    {
        $prefix = addcslashes(mb_strtoupper($text), '\\%_').'%';
        $query = Reservation::query()->where('property_id', $property->id)->select('id', 'reference', 'status', 'booker_guest_id');

        $query->where(function (Builder $query) use ($property, $text, $prefix, $support, $canViewGuests, $canViewRooms): void {
            $query->where('reference', 'like', $prefix);

            if (! $support) {
                $query->orWhereLike('group_name', addcslashes($text, '\\%_').'%');

                if ($canViewGuests) {
                    $query->orWhereIn('booker_guest_id', $this->guests->matching($property, $text)->select('id'));
                }

                if ($canViewRooms) {
                    $query->orWhereHas('units', fn (Builder $units) => $units->where('property_id', $property->id)
                        ->whereHas('nights', fn (Builder $nights) => $nights->whereIn('room_id', $this->rooms->matching($property, $text)->select('id'))));
                }
            }
        });

        if (! $support) {
            $query->with(['booker' => fn (BelongsTo $guests) => $guests->where('property_id', $property->id)->select('id', 'full_name')]);
        }

        return $query->orderByRaw('reference = ? desc', [mb_strtoupper($text)])->orderBy('reference')->orderBy('id')->limit(5)->get()
            ->map(fn (Reservation $reservation): CommandResultData => CommandResultData::fromReservation($reservation, $property, $support))->all();
    }
}
