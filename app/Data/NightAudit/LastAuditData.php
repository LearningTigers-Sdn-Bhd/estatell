<?php

namespace App\Data\NightAudit;

use App\Enums\AuditTrigger;
use App\Models\NightAudit;
use Spatie\LaravelData\Data;

/**
 * The last audit that closed a date, so the sheet can say it already ran: "28 Oct was closed automatically at 00:00."
 */
class LastAuditData extends Data
{
    /**
     * @param  string  $date  `Y-m-d`, the business date it closed.
     * @param  string|null  $runBy  The name of the user who ran it. Null for the scheduler.
     * @param  string  $completedDay  `Y-m-d` on the property's clock.
     * @param  string  $completedTime  `H:i` on the property's clock.
     */
    public function __construct(
        public string $date,
        public AuditTrigger $trigger,
        public ?string $runBy,
        public bool $forced,
        public string $completedDay,
        public string $completedTime,
    ) {}

    public static function fromAudit(NightAudit $audit, string $timeZone): self
    {
        $completedAt = ($audit->completed_at ?? $audit->updated_at)->setTimezone($timeZone);

        return new self(
            date: $audit->businessDate->date->toDateString(),
            trigger: $audit->trigger,
            runBy: $audit->runBy?->name,
            forced: $audit->forced,
            completedDay: $completedAt->toDateString(),
            completedTime: $completedAt->format('H:i'),
        );
    }
}
