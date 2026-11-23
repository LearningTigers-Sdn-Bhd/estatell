<?php

namespace App\Models;

use App\Enums\EventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill for people who are not staying: a wedding dinner, a meeting, a walk-in lunch. It has one folio, made with
 * the event, and one payer, a guest or a company. See docs/33-facilities/03-events-and-event-folios.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $number
 * @property string $name
 * @property int|null $payer_guest_id
 * @property int|null $payer_company_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property EventStatus $status
 * @property string|null $notes
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['number', 'name', 'payer_guest_id', 'payer_company_id', 'starts_on', 'ends_on', 'status', 'notes', 'cancelled_at', 'cancelled_by'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => EventStatus::class,
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function payerGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'payer_guest_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function payerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'payer_company_id');
    }

    /** @return HasMany<Folio, $this> */
    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }
}
