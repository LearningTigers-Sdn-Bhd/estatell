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
 * Forced column, which prints "Yes: reason" in the PDF and the spreadsheets. `exceptions` is "open / total"; `exceptions_open` and `exceptions_total` are its two counts. The `_date` and `_time` columns split `started` and `completed`.
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
        public string $started_date,
        public string $started_time,
        public string $completed,
        public string $completed_date,
        public string $completed_time,
        public bool $forced,
        public ?string $forceReason,
        public string $exceptions,
        public int $exceptions_open,
        public int $exceptions_total,
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
            'started_date' => $this->started_date,
            'started_time' => $this->started_time,
            'completed' => $this->completed,
            'completed_date' => $this->completed_date,
            'completed_time' => $this->completed_time,
            'forced' => $this->forcedText(),
            'exceptions' => $this->exceptions,
            'exceptions_open' => (string) $this->exceptions_open,
            'exceptions_total' => (string) $this->exceptions_total,
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
            'started_date' => $this->started_date,
            'started_time' => $this->started_time,
            'completed' => $this->completed,
            'completed_date' => $this->completed_date,
            'completed_time' => $this->completed_time,
            'forced' => $this->forcedText(),
            'exceptions' => $this->exceptions,
            'exceptions_open' => $this->exceptions_open,
            'exceptions_total' => $this->exceptions_total,
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
        $time = fn (?CarbonImmutable $moment, string $pattern): string => $moment?->setTimezone($timeZone)->format($pattern) ?? '';
        $started = $audit->started_at->toImmutable();
        $completed = $audit->completed_at?->toImmutable();
        $open = (int) $audit->getAttribute('open_exceptions_count');
        $total = (int) $audit->getAttribute('exceptions_count');

        return new self(
            key: $date,
            date: $date,
            status: BadgeData::fromAuditStatus($audit->status),
            trigger: $audit->trigger->label(),
            run_by: $audit->runBy->name ?? '',
            started: $time($started, 'j M Y H:i'),
            started_date: $time($started, 'j M Y'),
            started_time: $time($started, 'H:i'),
            completed: $time($completed, 'j M Y H:i'),
            completed_date: $time($completed, 'j M Y'),
            completed_time: $time($completed, 'H:i'),
            forced: $audit->forced,
            forceReason: $audit->force_reason,
            exceptions: "{$open} / {$total}",
            exceptions_open: $open,
            exceptions_total: $total,
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
