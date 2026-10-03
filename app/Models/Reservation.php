<?php

namespace App\Models;

use App\Enums\BillingMode;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One booking of a property: one or more units (a room or a bed each). A group is a reservation with `group_name` set.
 * `status` is derived from the units by `SyncReservationStatus`. See docs/12-reservations/01-reservation-shape.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $reference
 * @property ReservationStatus $status
 * @property int $booker_guest_id
 * @property int|null $company_id No foreign key until the city ledger module adds companies.
 * @property string|null $group_name
 * @property BillingMode $billing_mode
 * @property ReservationSource $source
 * @property string|null $channel_name
 * @property string|null $external_reference
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $cancellation_requested_at
 * @property int|null $cancellation_requested_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'booker_guest_id', 'company_id', 'group_name', 'billing_mode', 'source', 'channel_name', 'external_reference', 'notes',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'billing_mode' => BillingMode::class,
            'source' => ReservationSource::class,
            'cancellation_requested_at' => 'datetime',
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
     * @return BelongsTo<Guest, $this>
     */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'booker_guest_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancellationRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    /**
     * @return HasMany<ReservationUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ReservationUnit::class);
    }

    /**
     * @return HasMany<FolioRoutingRule, $this>
     */
    public function routingRules(): HasMany
    {
        return $this->hasMany(FolioRoutingRule::class);
    }
}
