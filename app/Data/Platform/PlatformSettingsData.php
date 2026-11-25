<?php

namespace App\Data\Platform;

use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use App\Models\PlatformSetting;
use Spatie\LaravelData\Data;

class PlatformSettingsData extends Data
{
    public function __construct(
        public bool $requireGoLiveApproval,
        public DormStrategy $defaultDormStrategy,
        public MediaDisk $mediaDisk,
        public bool $aiBookingWebsiteEnabled,
        public bool $aiStaffHelperEnabled,
    ) {}

    public static function fromModel(PlatformSetting $settings): self
    {
        return new self(
            requireGoLiveApproval: $settings->require_go_live_approval,
            defaultDormStrategy: $settings->default_dorm_strategy,
            mediaDisk: $settings->media_disk,
            aiBookingWebsiteEnabled: $settings->ai_booking_website_enabled,
            aiStaffHelperEnabled: $settings->ai_staff_helper_enabled,
        );
    }
}
