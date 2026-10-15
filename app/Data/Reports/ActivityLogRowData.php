<?php

namespace App\Data\Reports;

use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Data;

class ActivityLogRowData extends Data
{
    public function __construct(
        public int $id,
        public string $description,
        public ?string $event,
        public ?string $subject,
        public string $causerName,
        public string $createdAt,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        return new self(
            id: $activity->id,
            description: __($activity->description),
            event: $activity->event,
            subject: $activity->subject_type === null || $activity->subject_id === null
                ? null
                : Str::headline(class_basename($activity->subject_type)).' #'.$activity->subject_id,
            causerName: $activity->causer instanceof User
                ? $activity->causer->name
                : ($activity->causer_id === null ? __('System') : __('Unknown user')),
            createdAt: $activity->created_at->toIso8601String(),
        );
    }
}
