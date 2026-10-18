<?php

namespace App\Models;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a property's bookings come from, in the property's own words (Phone, WhatsApp, Booking.com). The type carries
 * the rules. `is_system` rows (Company / Travel agent, Walk-in) cannot be renamed or turned off. The badge is an icon or
 * one or two letters on a swatch color. See docs/12-reservations/11-booking-sources.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property BookingSourceType $type
 * @property int|null $ota_channel_id
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 * @property BookingSourceIcon|null $badge_icon
 * @property string|null $badge_letters
 * @property SwatchColor $badge_color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name', 'type', 'ota_channel_id', 'is_active', 'sort_order', 'badge_icon', 'badge_letters', 'badge_color',
])]
class BookingSource extends Model
{
    /** @use HasFactory<BookingSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BookingSourceType::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'badge_icon' => BookingSourceIcon::class,
            'badge_color' => SwatchColor::class,
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
     * @return BelongsTo<OtaChannel, $this>
     */
    public function otaChannel(): BelongsTo
    {
        return $this->belongsTo(OtaChannel::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @param  Builder<BookingSource>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Staff rows first, in their order; system rows at the end.
     *
     * @param  Builder<BookingSource>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('is_system')->orderBy('sort_order')->orderBy('id');
    }
}
