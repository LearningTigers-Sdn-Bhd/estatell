<?php

namespace App\Data\NightAudit;

use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use Spatie\LaravelData\Data;

/**
 * The audit of one business date: its state, its steps and how many exceptions it left open.
 */
class NightAuditProgressData extends Data
{
    /**
     * @param  string  $date  `Y-m-d`, the business date the audit closes.
     * @param  string  $updatedAt  ISO 8601. It changes each time the audit starts again or stops, so the browser can tell a resumed run from the failure it started from.
     * @param  list<AuditStepData>  $steps
     */
    public function __construct(
        public string $date,
        public AuditStatus $status,
        public string $updatedAt,
        public array $steps,
        public int $openExceptions,
    ) {}

    public static function fromAudit(NightAudit $audit): self
    {
        $rows = $audit->steps()->get()->keyBy(fn ($row): string => $row->step->value);

        return new self(
            date: $audit->businessDate->date->toDateString(),
            status: $audit->status,
            updatedAt: $audit->updated_at->toIso8601String(),
            steps: array_map(fn (AuditStep $step): AuditStepData => AuditStepData::fromStep($step, $rows->get($step->value)), AuditStep::runnable()),
            openExceptions: $audit->exceptions()->open()->count(),
        );
    }
}
