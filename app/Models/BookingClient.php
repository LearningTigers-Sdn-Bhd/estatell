<?php

namespace App\Models;

use App\Enums\BookingClientType;
use Carbon\CarbonImmutable;
use Database\Factories\BookingClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * An app that calls the booking engine API: the microsite, an owner's website or the demo. It owns its Sanctum token
 * and has no `Authenticatable` contract, like a kiosk: read it with the guard's `user()`, never `id()`. Only an API only
 * client has a property. See docs/28-api-access/04-booking-engine-microsite.md.
 *
 * @property int $id
 * @property BookingClientType $type
 * @property int|null $property_id
 * @property string $name
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'property_id', 'name', 'last_used_at', 'revoked_at'])]
class BookingClient extends Model implements HasApiTokensContract
{
    /** @use HasFactory<BookingClientFactory> */
    use HasApiTokens, HasFactory;

    public const string ABILITY_LISTING = 'booking:listing';

    public const string ABILITY_SITE = 'booking:site';

    public const string ABILITY_CONTENT = 'booking:content';

    public const string ABILITY_SEARCH = 'booking:search';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => BookingClientType::class,
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
