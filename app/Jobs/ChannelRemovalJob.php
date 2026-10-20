<?php

namespace App\Jobs;

use App\Actions\Channels\QueueChannelRemoval;
use App\Actions\Channels\RecordChannelAlert;
use App\Actions\Channels\RecordChannelRemovalProgress;
use App\Actions\Channels\RemoveChannelRatePlan;
use App\Actions\Channels\RemoveChannelRoomType;
use App\Actions\Rates\ArchiveRatePlan;
use App\Actions\Rates\SyncRatePlanRoomTypes;
use App\Actions\Rooms\ArchiveRoomType;
use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelRemovalKind;
use App\Enums\ChannelRemovalState;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\ChannelRemovalBlocked;
use App\Services\Channels\ChannelActionResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Removes a rate plan, a room type or some pairs from the channel manager, then archives or unlinks it as staff asked.
 * A rate limit or outage tries again after a while. A refusal (an OTA still uses it) stops here: nothing is archived,
 * the Channels tab gets an alert and the staff member who asked gets a notification. See
 * docs/21-channels/02-mappings-and-capability.md#archive-guard.
 */
class ChannelRemovalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 300;

    /**
     * @param  list<int>  $wantedRoomTypeIds
     * @param  list<int>  $removedRoomTypeIds
     */
    public function __construct(
        public readonly ChannelRemovalKind $kind,
        public readonly int $subjectId,
        public readonly int $actorId,
        public readonly array $wantedRoomTypeIds = [],
        public readonly array $removedRoomTypeIds = [],
    ) {}

    public function handle(RemoveChannelRatePlan $removeRatePlan, RemoveChannelRoomType $removeRoomType, ArchiveRatePlan $archiveRatePlan, ArchiveRoomType $archiveRoomType, SyncRatePlanRoomTypes $syncRoomTypes, RecordChannelAlert $recordAlert, RecordChannelRemovalProgress $recordProgress): void
    {
        $actor = User::query()->find($this->actorId);
        $subject = $this->kind === ChannelRemovalKind::RoomType ? RoomType::query()->find($this->subjectId) : RatePlan::query()->find($this->subjectId);

        if ($actor === null || $subject === null) {
            $this->forget();

            return;
        }

        $failure = $this->remove($actor, $removeRatePlan, $removeRoomType);

        if ($failure?->retryable) {
            $this->release(120);

            return;
        }

        if ($failure !== null) {
            $this->block($actor, $subject, $failure->message, $recordAlert);
            $recordProgress($actor->id, $this->kind, $this->subjectId, $subject->name, ChannelRemovalState::Blocked, __(':name is still used by an OTA, so it was not archived. The estatell team has been told.', ['name' => $subject->name]));
            $this->forget();

            return;
        }

        $done = match ($this->kind) {
            ChannelRemovalKind::RatePlan => $archiveRatePlan($actor, $subject),
            ChannelRemovalKind::RoomType => $archiveRoomType($actor, $subject),
            ChannelRemovalKind::Unlink => $syncRoomTypes($actor, $subject, $this->wantedRoomTypeIds),
        };

        if ($done) {
            $recordProgress($actor->id, $this->kind, $this->subjectId, $subject->name, ChannelRemovalState::Done, $this->kind === ChannelRemovalKind::Unlink ? __('Room types saved.') : __(':name archived.', ['name' => $subject->name]));
        } else {
            $this->block($actor, $subject, __('It was removed from the channels, but the change could not be applied. Open it and try again.'), $recordAlert);
            $recordProgress($actor->id, $this->kind, $this->subjectId, $subject->name, ChannelRemovalState::Blocked, __('It was removed from the channels, but the change could not be applied. Open it and try again.'));
        }

        $this->forget();
    }

    public function failed(Throwable $exception): void
    {
        $this->forget();
    }

    /**
     * Remove every mapping the change touches, stopping at the first one Channex does not take.
     */
    private function remove(User $actor, RemoveChannelRatePlan $removeRatePlan, RemoveChannelRoomType $removeRoomType): ?ChannelActionResult
    {
        $ratePlanMappings = ChannelRatePlanMapping::query()->with('ratePlan', 'roomType', 'channelProperty.property');

        match ($this->kind) {
            ChannelRemovalKind::RatePlan => $ratePlanMappings->where('rate_plan_id', $this->subjectId),
            ChannelRemovalKind::RoomType => $ratePlanMappings->where('room_type_id', $this->subjectId),
            ChannelRemovalKind::Unlink => $ratePlanMappings->where('rate_plan_id', $this->subjectId)->whereIn('room_type_id', $this->removedRoomTypeIds),
        };

        foreach ($ratePlanMappings->get() as $mapping) {
            $result = $removeRatePlan($actor, $mapping);

            if (! $result->ok) {
                return $result;
            }
        }

        if ($this->kind === ChannelRemovalKind::RoomType) {
            foreach (ChannelRoomTypeMapping::query()->with('roomType', 'channelProperty.property')->where('room_type_id', $this->subjectId)->get() as $mapping) {
                $result = $removeRoomType($actor, $mapping);

                if (! $result->ok) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function block(User $actor, RatePlan|RoomType $subject, string $reason, RecordChannelAlert $recordAlert): void
    {
        $property = Property::query()->find($subject->property_id);
        $channelProperty = ChannelProperty::query()->where('property_id', $subject->property_id)->first();

        if ($channelProperty !== null) {
            $recordAlert($channelProperty, ChannelAlertEvent::RemovalBlocked, ['message' => "{$subject->name}: {$reason}"]);
        }

        if ($property !== null) {
            $actor->notify(new ChannelRemovalBlocked($this->kind, $subject->name, $property->code, $property->name));
        }
    }

    private function forget(): void
    {
        Cache::forget(QueueChannelRemoval::pendingKey($this->kind, $this->subjectId));
    }
}
