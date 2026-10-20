<?php

namespace App\Services\Rooms;

use App\Data\Navigation\CommandResultData;
use App\Models\Property;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;

class RoomCommandSearch
{
    /** @return Builder<Room> */
    public function matching(Property $property, string $text): Builder
    {
        return Room::query()->where('property_id', $property->id)->active()->whereLike('number', addcslashes($text, '\\%_').'%');
    }

    /** @return list<CommandResultData> */
    public function search(Property $property, string $text): array
    {
        return array_values($this->matching($property, $text)->select('id', 'number', 'room_type_id', 'room_group_id')
            ->with(['roomType:id,name', 'roomGroup:id,name', 'status:room_id,status'])
            ->orderByRaw('lower(number) = lower(?) desc', [$text])->orderBy('number')->orderBy('id')->limit(5)->get()
            ->map(fn (Room $room): CommandResultData => CommandResultData::fromRoom($room, $property))->all());
    }
}
