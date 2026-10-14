<?php

namespace App\Data\Platform;

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use Spatie\LaravelData\Data;

/**
 * The platform-wide settings a platform admin saves. The strategy and the disk are the enums the settings row casts to.
 */
class PlatformSettingsInputData extends Data
{
    public function __construct(
        public bool $requireGoLiveApproval,
        public DormStrategy $defaultDormStrategy,
        public MediaDisk $mediaDisk,
    ) {}
}
