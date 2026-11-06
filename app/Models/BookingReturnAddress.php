<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BookingReturnAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A page on an API only owner's website that a guest may return to after Curlec's checkout. A payment names one, and
 * any other address is refused. See docs/29-booking-engine/09-api-only-mode.md#access.
 *
 * @property int $id
 * @property int $property_id
 * @property string $url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'url'])]
class BookingReturnAddress extends Model
{
    /** @use HasFactory<BookingReturnAddressFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
