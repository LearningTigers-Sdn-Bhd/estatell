<?php

namespace App\Models;

use App\Enums\ApiTokenKind;
use Carbon\CarbonImmutable;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One API credential for one property. Only SHA-256 hashes are stored; the plaintext token is shown once, when pairing
 * completes. A kiosk row exists before the kiosk pairs: `pairing_code_hash` is set until the code is used, then cleared.
 * `scopes` is a space-separated list, for example `kiosk:*`.
 *
 * @property int $id
 * @property int $property_id
 * @property ApiTokenKind $kind
 * @property string $name
 * @property string|null $device_name
 * @property string|null $token_hash
 * @property string|null $pairing_code_hash
 * @property CarbonImmutable|null $pairing_expires_at
 * @property string $scopes
 * @property CarbonImmutable|null $paired_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['kind', 'name', 'device_name', 'token_hash', 'pairing_code_hash', 'pairing_expires_at', 'scopes', 'paired_at', 'last_used_at', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash', 'pairing_code_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => ApiTokenKind::class,
            'pairing_expires_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
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
