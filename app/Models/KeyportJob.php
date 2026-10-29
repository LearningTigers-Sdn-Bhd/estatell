<?php

namespace App\Models;

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use Carbon\CarbonImmutable;
use Database\Factories\KeyportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card operation for a Keyport PC. The payload carries everything the agent needs; the agent derives nothing.
 *
 * @property int $id
 * @property string $public_id
 * @property int $property_id
 * @property int|null $device_id
 * @property KeyportJobType $type
 * @property KeyportJobStatus $status
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $result
 * @property string|null $serial
 * @property string|null $error_code
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $lease_until
 * @property CarbonImmutable|null $finished_at
 * @property string $idempotency_key
 * @property int|null $issued_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'payload'])]
class KeyportJob extends Model
{
    /** @use HasFactory<KeyportJobFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => KeyportJobType::class,
            'status' => KeyportJobStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'lease_until' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
     * @return BelongsTo<KeyportDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(KeyportDevice::class);
    }
}
