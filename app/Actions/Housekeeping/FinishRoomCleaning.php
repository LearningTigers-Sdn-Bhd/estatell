<?php

namespace App\Actions\Housekeeping;

use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\HousekeepingRefusal;
use App\Enums\WebhookEvent;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinishRoomCleaning
{
    public function __construct(private MarkCleanedRoomClean $markClean) {}

    /**
     * Record OUT: the open cleaning of the room ends now, and a Dirty room becomes Clean.
     */
    public function __invoke(User $actor, Room $room): ?HousekeepingRefusal
    {
        return DB::transaction(function () use ($actor, $room): ?HousekeepingRefusal {
            RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $cleaning = RoomCleaning::query()->where('room_id', $room->id)->whereNull('finished_at')->first();

            if ($cleaning === null) {
                return HousekeepingRefusal::CleaningNotStarted;
            }

            $cleaning->forceFill(['finished_at' => now(), 'updated_by' => $actor->id])->save();

            activity()
                ->performedOn($cleaning)
                ->causedBy($actor)
                ->event('room_cleaning.finished')
                ->withProperties(['property_id' => $room->property_id, 'room_id' => $room->id, 'housekeeper_id' => $cleaning->housekeeper_id])
                ->log('Room cleaning finished');

            ($this->markClean)($actor, $room);

            app(TriggerWebhook::class)(WebhookEvent::RoomCleaned, $room->property_id, Room::class, $room->id);

            return null;
        });
    }
}
