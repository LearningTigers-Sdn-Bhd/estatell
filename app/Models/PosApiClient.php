<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosApiClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * One external POS system of one property, and the owner of its Sanctum token (`pos:*`). Like `Kiosk`, it implements only
 * the token contract. `RevokePosApiClient` deletes the token while the row stays. See docs/33-pos/03-pos-api.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'last_used_at', 'revoked_at'])]
class PosApiClient extends Model implements HasApiTokensContract
{
    /** @use HasFactory<PosApiClientFactory> */
    use HasApiTokens, HasFactory;

    /** The one ability a POS token has. Always pass it: `createToken` otherwise grants every ability. */
    public const string TOKEN_ABILITY = 'pos:*';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
