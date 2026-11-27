<?php

namespace App\Services\Channels\Dorm;

use App\Enums\DormStrategy;
use App\Models\RoomType;
use App\Services\Platform\PlatformSettings;

/**
 * The strategy a dorm room type is sent with: the platform default (`platform_settings.default_dorm_strategy`, set
 * by platform admins), and BedAsUnit while the default is not yet available (NativeDorm waits for the staging
 * checks). The property and room type overrides come with NativeDorm. See
 * docs/21-channels/03-dorm-strategies.md#where-the-setting-lives.
 */
class DormStrategyResolver
{
    public function __construct(private PlatformSettings $platformSettings) {}

    public function for(RoomType $roomType): DormChannelStrategy
    {
        $strategy = $this->platformSettings->current()->default_dorm_strategy;

        return app(in_array($strategy, DormStrategy::available(), true) ? $strategy->strategy() : DormStrategy::BedAsUnit->strategy());
    }
}
