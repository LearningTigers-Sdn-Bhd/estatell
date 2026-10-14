<?php

namespace App\Actions\Rooms;

use App\Enums\SellUnit;
use App\Models\Bed;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use App\Services\Rooms\BedLabelGenerator;
use Illuminate\Support\Facades\DB;

class GenerateBeds
{
    public function __construct(private BedLabelGenerator $labels) {}

    /**
     * Add `$count` beds to each room. The prefix defaults to the room's letter, which is its place among the
     * property's active Bed rooms, so a room keeps its letter whichever rooms are picked. Numbers continue
     * after the room's active labels with the same prefix, so running it again adds A5, A6 ... after A4. Rooms that are archived or
     * not Bed rooms are skipped; the request rejects them before this runs.
     *
     * @param  list<int>  $roomIds
     * @return int The number of beds created.
     */
    public function __invoke(Property $property, User $actor, array $roomIds, int $count, ?string $prefix = null): int
    {
        return DB::transaction(function () use ($property, $actor, $roomIds, $count, $prefix): int {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $bedRooms = $property->rooms()->active()
                ->whereHas('roomType', fn ($query) => $query->where('sell_unit', SellUnit::Bed->value))
                ->ordered()
                ->get();

            $letterIndex = $bedRooms->pluck('id')->flip();
            $targets = $bedRooms->whereIn('id', $roomIds);
            $created = 0;

            foreach ($targets as $room) {
                $roomPrefix = $prefix ?? $this->labels->letter((int) $letterIndex[$room->id]);
                $start = $this->labels->nextNumber($roomPrefix, $room->beds()->active()->pluck('label'));
                $position = (int) $room->beds()->max('position');

                foreach ($this->labels->labels($roomPrefix, $count, $start) as $label) {
                    $bed = new Bed(['label' => $label, 'position' => ++$position]);
                    $bed->room_id = $room->id;
                    $bed->save();
                    $created++;
                }
            }

            activity()
                ->causedBy($actor)
                ->event('bed.generated')
                ->withProperties([
                    'property_id' => $property->id,
                    'room_ids' => $targets->pluck('id')->values()->all(),
                    'count_per_room' => $count,
                    'prefix' => $prefix,
                    'created' => $created,
                ])
                ->log('Beds added');

            return $created;
        });
    }
}
