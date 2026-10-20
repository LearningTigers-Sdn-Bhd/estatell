<?php

namespace App\Services\Channels;

use App\Models\Property;
use App\Models\RoomType;

/**
 * Lists what a sync to the channel manager would send, before anything is sent. Read-only.
 */
class ChannelStructurePreview
{
    /** The Channex limits per property. The real numbers come back from Channex with the property. */
    public const MAX_ROOM_TYPES = 20;

    public const MAX_RATE_PLANS = 200;

    public function __construct(private ChannelCapability $capability) {}

    public function forProperty(Property $property): ChannelStructure
    {
        $roomTypes = $property->roomTypes()
            ->active()
            ->ordered()
            ->withCount(['rooms as active_rooms_count' => fn ($rooms) => $rooms->active()])
            ->with(['ratePlans' => fn ($plans) => $plans->active()->ordered()->with(['packagePrices', 'arrivalWeekdays'])])
            ->get();

        $pairs = [];

        /** @var RoomType $roomType */
        foreach ($roomTypes as $roomType) {
            foreach ($roomType->ratePlans as $ratePlan) {
                $pairs[] = new ChannelStructurePair($roomType, $ratePlan, $this->capability->forPair($roomType, $ratePlan));
            }
        }

        $structure = new ChannelStructure($pairs);

        return new ChannelStructure($pairs, $this->blockedReason($structure));
    }

    private function blockedReason(ChannelStructure $structure): ?string
    {
        if ($structure->ratePlanCount() === 0) {
            return __('Nothing can be sent yet. Every room type and rate plan pair is skipped.');
        }

        if ($structure->roomTypeCount() > self::MAX_ROOM_TYPES) {
            return __('Channex takes at most :max room types per property.', ['max' => self::MAX_ROOM_TYPES]);
        }

        if ($structure->ratePlanCount() > self::MAX_RATE_PLANS) {
            return __('Channex takes at most :max rate plans per property.', ['max' => self::MAX_RATE_PLANS]);
        }

        return null;
    }
}
