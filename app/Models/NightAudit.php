<?php

namespace App\Models;

use App\Enums\AuditStatus;
use App\Enums\AuditTrigger;
use Database\Factories\NightAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of the night audit for one business date. A failed run is resumed on the same row.
 *
 * @property int $id
 * @property int $property_id
 * @property int $business_date_id
 * @property AuditTrigger $trigger
 * @property AuditStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property int|null $run_by
 * @property bool $forced
 * @property string|null $force_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_date_id', 'trigger', 'status', 'started_at', 'completed_at', 'run_by', 'forced', 'force_reason'])]
class NightAudit extends Model
{
    /** @use HasFactory<NightAuditFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => AuditTrigger::class,
            'status' => AuditStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'forced' => 'boolean',
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
     * @return BelongsTo<BusinessDate, $this>
     */
    public function businessDate(): BelongsTo
    {
        return $this->belongsTo(BusinessDate::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /**
     * @return HasMany<NightAuditStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(NightAuditStep::class);
    }

    /**
     * @return HasMany<NightAuditException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(NightAuditException::class);
    }
}
