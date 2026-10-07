<?php

namespace App\Data\NightAudit;

use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Models\NightAuditStep;
use Spatie\LaravelData\Data;

/**
 * One step of the audit as the sheet shows it. A step with no row yet has no status.
 */
class AuditStepData extends Data
{
    public function __construct(
        public AuditStep $step,
        public string $label,
        public ?AuditStepStatus $status,
        public int $processed,
        public int $failed,
        public ?string $error,
    ) {}

    public static function fromStep(AuditStep $step, ?NightAuditStep $row): self
    {
        return new self(
            step: $step,
            label: $step->label(),
            status: $row?->status,
            processed: $row->processed_count ?? 0,
            failed: $row->failed_count ?? 0,
            error: $row?->error_message,
        );
    }
}
