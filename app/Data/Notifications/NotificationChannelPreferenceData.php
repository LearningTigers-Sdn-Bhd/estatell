<?php

namespace App\Data\Notifications;

use App\Enums\NotificationChannel;
use Spatie\LaravelData\Data;

class NotificationChannelPreferenceData extends Data
{
    public function __construct(
        public NotificationChannel $channel,
        public string $label,
        public bool $supported,
        public bool $enabled,
        public bool $editable,
        public ?string $disabledReason,
    ) {}
}
