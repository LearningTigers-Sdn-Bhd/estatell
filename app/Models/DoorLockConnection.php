<?php

namespace App\Models;

use App\Enums\PasscodeMode;
use Carbon\CarbonImmutable;
use Database\Factories\DoorLockConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A property's link to its TTLock account. One row per property. The tokens are stored encrypted and are never sent
 * to the browser. The TTLock password is used once to get them and is not saved.
 *
 * @property int $id
 * @property int $property_id
 * @property string $ttlock_username
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property bool $enabled
 * @property PasscodeMode $passcode_mode
 * @property CarbonImmutable|null $last_tested_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['ttlock_username', 'access_token', 'refresh_token', 'token_expires_at', 'enabled', 'passcode_mode', 'last_tested_at', 'last_error'])]
class DoorLockConnection extends Model
{
    /** @use HasFactory<DoorLockConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'enabled' => 'boolean',
            'passcode_mode' => PasscodeMode::class,
            'last_tested_at' => 'immutable_datetime',
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
