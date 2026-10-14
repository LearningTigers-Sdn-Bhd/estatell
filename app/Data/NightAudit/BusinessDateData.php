<?php

namespace App\Data\NightAudit;

use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * The current business date for the sidebar indicator. The browser works out the state
 * (in sync, audit pending, overdue) from `date`, `timeZone` and `serverNow`.
 */
class BusinessDateData extends Data
{
    /**
     * @param  string  $date  `Y-m-d`.
     * @param  string  $auditTime  `H:i`, local to the property time zone.
     * @param  string  $serverNow  ISO 8601 instant, so the browser can correct a wrong device clock.
     */
    public function __construct(
        public string $date,
        public BusinessDateStatus $status,
        public string $timeZone,
        public string $auditTime,
        public string $serverNow,
    ) {}

    public static function fromBusinessDate(BusinessDate $businessDate, Property $property): self
    {
        $settings = $property->settings;

        return new self(
            date: $businessDate->date->toDateString(),
            status: $businessDate->status,
            timeZone: $settings->time_zone ?? config('app.timezone'),
            auditTime: substr($settings->audit_time ?? '02:00', 0, 5),
            serverNow: now()->toIso8601String(),
        );
    }
}
