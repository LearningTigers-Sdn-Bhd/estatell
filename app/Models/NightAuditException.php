<?php

namespace App\Models;

use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use Database\Factories\NightAuditExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One unit or add-on a night audit could not resolve. Open rows carry to the next day's readiness panel.
 *
 * @property int $id
 * @property int $property_id
 * @property int $night_audit_id
 * @property AuditStep $step
 * @property int|null $reservation_unit_id
 * @property AuditExceptionType $type
 * @property string $message
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['step', 'reservation_unit_id', 'type', 'message', 'resolved_at', 'resolved_by'])]
class NightAuditException extends Model
{
    /** @use HasFactory<NightAuditExceptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => AuditStep::class,
            'type' => AuditExceptionType::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<NightAuditException>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
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

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
