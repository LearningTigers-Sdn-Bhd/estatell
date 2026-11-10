<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KeyportDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A front desk PC running Keyport. Only a hash of its token is stored; the token itself is shown once, at pairing.
 *
 * @property int $id
 * @property string $public_id
 * @property int $property_id
 * @property string $name
 * @property string|null $platform
 * @property string|null $agent_version
 * @property string $token_hash
 * @property CarbonImmutable|null $last_seen_at
 * @property bool $simulator
 * @property bool $client_code_present
 * @property array<int, array{kind: string, state: string}>|null $reader_state
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'platform', 'agent_version'])]
class KeyportDevice extends Model
{
    /** @use HasFactory<KeyportDeviceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'simulator' => 'boolean',
            'client_code_present' => 'boolean',
            'reader_state' => 'array',
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
     * @return HasMany<KeyportJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(KeyportJob::class, 'device_id');
    }
}
