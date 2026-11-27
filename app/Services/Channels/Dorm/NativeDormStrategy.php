<?php

namespace App\Services\Channels\Dorm;

use App\Models\RoomType;

/**
 * The channel manager's own dorm kind. Its availability and OTA behaviour are not documented, so it is refused until
 * the staging checks pass. See docs/21-channels/06-staging-verification.md#dorm-checks-wip.
 */
class NativeDormStrategy implements DormChannelStrategy
{
    public function refusal(): ?string
    {
        return __('Native dorms on channels are pending verification.');
    }

    public function roomKind(): string
    {
        return 'dorm';
    }

    public function adults(RoomType $roomType): int
    {
        return 1;
    }

    public function children(RoomType $roomType): int
    {
        return 0;
    }
}
