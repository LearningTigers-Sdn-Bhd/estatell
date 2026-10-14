<?php

namespace App\Models;

use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NightAuditStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of one step of a night audit. A Completed row is skipped when the audit is resumed.
 *
 * @property int $id
 * @property int $property_id
 * @property int $night_audit_id
 * @property AuditStep $step
 * @property AuditStepStatus $status
 * @property int $processed_count
 * @property int $failed_count
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $error_message
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['step', 'status', 'processed_count', 'failed_count', 'started_at', 'finished_at', 'error_message'])]
class NightAuditStep extends Model
{
    /** @use HasFactory<NightAuditStepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => AuditStep::class,
            'status' => AuditStepStatus::class,
            'processed_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<NightAudit, $this>
     */
    public function nightAudit(): BelongsTo
    {
        return $this->belongsTo(NightAudit::class);
    }
}
