<?php

namespace App\Models;

use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property's link to one POS provider (Loyverse). Tokens and the webhook secret are encrypted and never sent to the
 * browser.
 *
 * @property int $id
 * @property int $property_id
 * @property PosProvider $provider
 * @property PosConnectionStatus $status
 * @property string|null $external_merchant_id
 * @property string|null $external_webhook_id The Loyverse webhook Estatell registered, removed again on disconnect.
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property string|null $webhook_secret
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'status', 'external_merchant_id', 'external_webhook_id', 'access_token', 'refresh_token', 'token_expires_at', 'webhook_secret', 'last_synced_at', 'last_error'])]
#[Hidden(['access_token', 'refresh_token', 'webhook_secret'])]
class PosConnection extends Model
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'provider' => PosProvider::class,
            'status' => PosConnectionStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
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
     * @return HasMany<PosOutletMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(PosOutletMapping::class);
    }
}
