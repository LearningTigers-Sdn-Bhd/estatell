<?php

namespace App\Actions\Channels;

use App\Enums\ChannelRemovalKind;
use App\Jobs\ChannelRemovalJob;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelArchiveGuard;
use Illuminate\Support\Facades\Cache;

class QueueChannelRemoval
{
    /** Minutes a queued removal blocks a second one for the same thing. */
    private const PENDING_MINUTES = 10;

    public function __construct(private ChannelArchiveGuard $guard) {}

    /**
     * Archive a rate plan or a room type, or unlink room types from a plan, when the channel manager still has it. The
     * removal there comes first, in the background, and only a removal that worked goes on to archive or unlink; an OTA
     * that still uses it stops it, and then nothing changes, the estatell team gets an alert and the staff member who
     * asked gets a notification. Returns null when the channel manager does not have it, so the caller archives or
     * unlinks as usual. Otherwise returns what to tell staff: queued, or refused (Channex is not set up, or the same
     * removal is already queued).
     *
     * `$wantedRoomTypeIds` and `$removedRoomTypeIds` are only for an unlink: the room types the plan should sell
     * afterwards, and the ones that go.
     *
     * @param  list<int>  $wantedRoomTypeIds
     * @param  list<int>  $removedRoomTypeIds
     */
    public function __invoke(User $actor, ChannelRemovalKind $kind, RatePlan|RoomType $subject, array $wantedRoomTypeIds = [], array $removedRoomTypeIds = []): ?ChannelActionResult
    {
        $onChannel = match ($kind) {
            ChannelRemovalKind::RatePlan => $subject instanceof RatePlan && $this->guard->ratePlanOnChannel($subject),
            ChannelRemovalKind::RoomType => $subject instanceof RoomType && $this->guard->roomTypeOnChannel($subject),
            ChannelRemovalKind::Unlink => $subject instanceof RatePlan && $this->guard->unlinkOnChannel($subject, $removedRoomTypeIds),
        };

        if (! $onChannel) {
            return null;
        }

        if (blank(config('services.channex.api_key'))) {
            return ChannelActionResult::failed(__('Channex is not set up on this server.'));
        }

        $key = self::pendingKey($kind, $subject->id);

        if (! Cache::add($key, true, now()->addMinutes(self::PENDING_MINUTES))) {
            return ChannelActionResult::failed(__(':name is already being removed from the channels.', ['name' => $subject->name]));
        }

        ChannelRemovalJob::dispatch($kind, $subject->id, $actor->id, $wantedRoomTypeIds, $removedRoomTypeIds);

        return ChannelActionResult::ok(__(':name is on the channels. It is being removed there first and the change applies in a moment.', ['name' => $subject->name]));
    }

    public static function pendingKey(ChannelRemovalKind $kind, int $id): string
    {
        return "channel-removal:{$kind->value}:{$id}";
    }
}
