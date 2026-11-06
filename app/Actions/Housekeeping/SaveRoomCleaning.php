<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingRefusal;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveRoomCleaning
{
    public function __construct(private HousekeepingDate $housekeepingDate, private MarkCleanedRoomClean $markClean) {}

    /**
     * Front office correct a cleaning, or add one housekeeping did not record. Times are `H:i` in the property's time
     * zone, on the cleaning's own date. A room keeps one open cleaning at most. When this gives a cleaning its OUT time
     * the room follows, as with OUT on the board. The activity log keeps the old and new values.
     */
    public function __invoke(User $actor, Room $room, ?RoomCleaning $cleaning, User $housekeeper, string $inTime, ?string $outTime): ?HousekeepingRefusal
    {
        return DB::transaction(function () use ($actor, $room, $cleaning, $housekeeper, $inTime, $outTime): ?HousekeepingRefusal {
            RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();

            $hasOtherOpen = RoomCleaning::query()
                ->where('room_id', $room->id)
                ->whereNull('finished_at')
                ->when($cleaning !== null, fn ($query) => $query->whereKeyNot($cleaning->id))
                ->exists();

            if ($outTime === null && $hasOtherOpen) {
                return HousekeepingRefusal::CleaningInProgress;
            }

            $property = $room->property->loadMissing('settings');
            $timeZone = $property->settings->time_zone ?? config('app.timezone');
            $date = $cleaning?->date->toDateString() ?? $this->housekeepingDate->for($property)->toDateString();
            $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse($date.' '.$time, $timeZone)->utc();
            $wasOpen = $cleaning === null || $cleaning->finished_at === null;
            $old = $cleaning === null ? null : [
                'housekeeper_id' => $cleaning->housekeeper_id,
                'started_at' => $cleaning->started_at->toIso8601String(),
                'finished_at' => $cleaning->finished_at?->toIso8601String(),
            ];
            $saved = $cleaning ?? new RoomCleaning([
                'property_id' => $room->property_id,
                'room_id' => $room->id,
                'date' => $date,
                'created_by' => $actor->id,
            ]);

            $saved->forceFill([
                'housekeeper_id' => $housekeeper->id,
                'started_at' => $at($inTime),
                'finished_at' => $outTime === null ? null : $at($outTime),
                'updated_by' => $cleaning === null ? null : $actor->id,
            ])->save();

            activity()
                ->performedOn($saved)
                ->causedBy($actor)
                ->event($cleaning === null ? 'room_cleaning.added' : 'room_cleaning.edited')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'old' => $old,
                    'new' => [
                        'housekeeper_id' => $housekeeper->id,
                        'started_at' => $saved->started_at->toIso8601String(),
                        'finished_at' => $saved->finished_at?->toIso8601String(),
                    ],
                ])
                ->log($cleaning === null ? 'Room cleaning added' : 'Room cleaning edited');

            if ($wasOpen && $outTime !== null) {
                ($this->markClean)($actor, $room);
            }

            return null;
        });
    }
}
