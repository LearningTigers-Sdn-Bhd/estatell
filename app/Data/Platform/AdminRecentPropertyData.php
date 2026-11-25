<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use App\Services\Platform\SignInLogDirectory;
use Spatie\LaravelData\Data;

class AdminRecentPropertyData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public BadgeData $status,
        /** "Went live" or "Signed up". */
        public string $event,
        /** The day that happened in Malaysia time, `Y-m-d`. */
        public string $at,
    ) {}

    public static function fromModel(Property $property): self
    {
        $wentLive = $property->went_live_at !== null && $property->went_live_at->greaterThanOrEqualTo($property->created_at);

        return new self(
            code: $property->code,
            name: $property->name,
            status: BadgeData::fromStatus($property->status),
            event: $wentLive ? __('Went live') : __('Signed up'),
            at: ($wentLive ? $property->went_live_at : $property->created_at)->setTimezone(SignInLogDirectory::TIME_ZONE)->toDateString(),
        );
    }
}
