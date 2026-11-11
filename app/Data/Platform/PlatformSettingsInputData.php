<?php

namespace App\Data\Platform;

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use Spatie\LaravelData\Data;

/**
 * A section's platform settings. Null fields are omitted, not written over other sections.
 */
class PlatformSettingsInputData extends Data
{
    public function __construct(
        public ?bool $requireGoLiveApproval = null,
        public ?DormStrategy $defaultDormStrategy = null,
        public ?MediaDisk $mediaDisk = null,
    ) {}
}
