<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KioskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * One kiosk of one property. The row exists before the kiosk pairs: `pairing_code_hash` is set until the code is used,
 * then cleared. The kiosk owns its Sanctum token, which `RevokeKiosk` deletes while this row stays. Only SHA-256 hashes
 * are stored. This model implements only the token contract; the middleware reads the owner with `user()`, never `id()`.
 * Staff access is separated by guards and authorization, not by this model's base class. See docs/28-api-access/02-kiosks.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string|null $device_name
 * @property string|null $pairing_code_hash
 * @property CarbonImmutable|null $pairing_expires_at
 * @property CarbonImmutable|null $paired_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'device_name', 'pairing_code_hash', 'pairing_expires_at', 'paired_at', 'last_used_at', 'revoked_at'])]
#[Hidden(['pairing_code_hash'])]
class Kiosk extends Model implements HasApiTokensContract
{
    /** @use HasFactory<KioskFactory> */
    use HasApiTokens, HasFactory;

    /** The one ability a kiosk token has. Always pass it: `createToken` otherwise grants every ability. */
    public const string TOKEN_ABILITY = 'kiosk:*';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
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
