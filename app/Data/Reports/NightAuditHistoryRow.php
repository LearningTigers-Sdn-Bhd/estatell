<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Enums\AuditStatus;
use App\Models\NightAudit;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Night audit history report: one night audit. The property names are the column keys of
 * `ReportKey::NightAuditHistory`. `key` and `date` are the business date (`Y-m-d`). `forced` and `forceReason` give the
 * Forced column, which prints "Yes: reason" in the PDF and the spreadsheets. `exceptions` is "open / total".
 * `occupancy` and `revenue` are null when the audit has no figures (not finished). `packet` is true for a Completed
 * audit: the screen shows a link to the packet PDF, and the exports leave the column empty.
 */
class NightAuditHistoryRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public BadgeData $status,
        public string $trigger,
        public string $run_by,
        public string $started,
        public string $completed,
        public bool $forced,
        public ?string $forceReason,
        public string $exceptions,
        public ?string $occupancy,
        public ?string $revenue,
        public bool $packet,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('j M Y'),
            'status' => $this->status->label,
            'trigger' => $this->trigger,
            'run_by' => $this->run_by,
            'started' => $this->started,
            'completed' => $this->completed,
            'forced' => $this->forcedText(),
            'exceptions' => $this->exceptions,
            'occupancy' => $this->occupancy === null ? '' : number_format((float) $this->occupancy, 2),
            'revenue' => $this->revenue === null ? '' : number_format((float) $this->revenue, 2),
            'packet' => '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'status' => $this->status->label,
            'trigger' => $this->trigger,
            'run_by' => $this->run_by,
            'started' => $this->started,
            'completed' => $this->completed,
            'forced' => $this->forcedText(),
            'exceptions' => $this->exceptions,
            'occupancy' => $this->occupancy === null ? '' : round((float) $this->occupancy, 2),
            'revenue' => $this->revenue === null ? '' : round((float) $this->revenue, 2),
            'packet' => '',
        ];
    }

    /**
     * @param  string  $timeZone  The time zone the start and end times are shown in.
     */
    public static function fromAudit(NightAudit $audit, string $timeZone, ?string $occupancy, ?Money $revenue): self
    {
        $date = $audit->businessDate->date->toDateString();
        $time = fn (?CarbonImmutable $moment): string => $moment === null ? '' : $moment->setTimezone($timeZone)->format('j M Y H:i');

        return new self(
            key: $date,
            date: $date,
            status: BadgeData::fromAuditStatus($audit->status),
            trigger: $audit->trigger->label(),
            run_by: $audit->runBy?->name ?? '',
            started: $time($audit->started_at->toImmutable()),
            completed: $time($audit->completed_at?->toImmutable()),
            forced: $audit->forced,
            forceReason: $audit->force_reason,
            exceptions: ((int) $audit->getAttribute('open_exceptions_count')).' / '.((int) $audit->getAttribute('exceptions_count')),
            occupancy: $occupancy,
            revenue: $revenue === null ? null : (string) $revenue->getAmount(),
            packet: $audit->status === AuditStatus::Completed,
        );
    }

    private function forcedText(): string
    {
        if (! $this->forced) {
            return '';
        }

        return $this->forceReason === null || $this->forceReason === '' ? __('Yes') : __('Yes').': '.$this->forceReason;
    }
}
