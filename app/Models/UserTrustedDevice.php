<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserTrustedDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that passed the new-device check for one user. See docs/01-platform/09-new-device-check.md.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $device_name
 * @property CarbonImmutable $last_used_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'device_name', 'last_used_at', 'expires_at'])]
class UserTrustedDevice extends Model
{
    /** @use HasFactory<UserTrustedDeviceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }
}
