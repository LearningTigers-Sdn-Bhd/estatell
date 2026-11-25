<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PosTerminalDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tablet or PC a manager registered as a shared POS till. Its browser holds a signed cookie with the token; only the
 * SHA-256 hash is stored here.
 *
 * @property int $id
 * @property int $property_id
 * @property int $pos_outlet_id
 * @property string $name
 * @property string $device_token_hash
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name'])]
#[Hidden(['device_token_hash'])]
class PosTerminalDevice extends Model
{
    /** @use HasFactory<PosTerminalDeviceFactory> */
    use HasFactory;

    /** Name of the signed, HttpOnly cookie that marks a browser as a registered terminal device. */
    public const string COOKIE = 'pos_terminal_device';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<PosOutlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    /**
     * @param  Builder<PosTerminalDevice>  $query
     * @return Builder<PosTerminalDevice>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
