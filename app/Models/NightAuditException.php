<?php

namespace App\Models;

use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Enums\UnitStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NightAuditExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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
     * A missed arrival whose unit is no longer Confirmed, or an overdue departure whose unit is no longer CheckedIn:
     * the unit was checked in, checked out, cancelled or marked no-show since, so the exception has nothing left to say.
     *
     * @param  Builder<NightAuditException>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $query->where(fn (Builder $either) => $either
            ->where(fn (Builder $missed) => $missed->where('type', AuditExceptionType::MissedArrival->value)
                ->whereHas('reservationUnit', fn (Builder $units) => $units->where('status', '!=', UnitStatus::Confirmed->value)))
            ->orWhere(fn (Builder $overdue) => $overdue->where('type', AuditExceptionType::OverdueDeparture->value)
                ->whereHas('reservationUnit', fn (Builder $units) => $units->where('status', '!=', UnitStatus::CheckedIn->value))));
    }

    /**
     * @param  Builder<NightAuditException>  $query
     */
    public function scopeNotStale(Builder $query): void
    {
        $query->whereNot(fn (Builder $stale) => $stale->stale());
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
