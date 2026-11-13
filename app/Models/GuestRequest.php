<?php

namespace App\Models;

use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use Carbon\CarbonImmutable;
use Database\Factories\GuestRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a guest asks for or complains about during a stay. See docs/25-guest-requests/01-guest-requests.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int|null $reservation_unit_id
 * @property int|null $room_id
 * @property GuestRequestType $type
 * @property GuestRequestSource $source
 * @property string $details
 * @property GuestRequestStatus $status
 * @property int|null $assigned_to
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $opened_business_date
 * @property int|null $opened_by
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $resolved_business_date
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property string|null $external_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'reservation_unit_id', 'room_id', 'type', 'source', 'details', 'status', 'assigned_to', 'opened_at', 'opened_business_date', 'opened_by', 'resolved_at', 'resolved_business_date', 'resolved_by', 'resolution_note', 'external_reference'])]
class GuestRequest extends Model
{
    /** @use HasFactory<GuestRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GuestRequestType::class,
            'source' => GuestRequestSource::class,
            'status' => GuestRequestStatus::class,
            'opened_at' => 'immutable_datetime',
            'opened_business_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
            'resolved_business_date' => 'immutable_date',
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
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }
}
