<?php

namespace App\Models;

use App\Enums\BusinessDateStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BusinessDateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One trading day of a property. Postings belong to a business date, not the calendar date.
 * A property has at most one date that is not Closed: the current one.
 *
 * @property int $id
 * @property int $property_id
 * @property CarbonImmutable $date
 * @property BusinessDateStatus $status
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'status', 'opened_at', 'closed_at', 'closed_by'])]
class BusinessDate extends Model
{
    /** @use HasFactory<BusinessDateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => BusinessDateStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Dates that are Open or Auditing.
     *
     * @param  Builder<BusinessDate>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', BusinessDateStatus::Closed->value);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasOne<NightAudit, $this>
     */
    public function nightAudit(): HasOne
    {
        return $this->hasOne(NightAudit::class);
    }
}
