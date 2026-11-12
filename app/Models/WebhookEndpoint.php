<?php

namespace App\Models;

use App\Enums\WebhookEvent;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL that receives signed JSON when an event it hears happens. An empty `property_id` hears every property. See
 * docs/32-webhooks/01-outgoing-webhooks.md.
 *
 * @property int $id
 * @property int|null $property_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property bool $is_active
 * @property CarbonImmutable|null $failing_since
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $disabled_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'name', 'url', 'secret', 'is_active', 'failing_since', 'disabled_at', 'disabled_reason'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    /**
     * Days with no successful delivery before the system turns an endpoint off.
     */
    public const int DISABLE_AFTER_DAYS = 3;

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'failing_since' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Active endpoints that hear the event at the property: the property's own and those for every property.
     *
     * @param  Builder<WebhookEndpoint>  $query
     */
    public function scopeListeningFor(Builder $query, WebhookEvent $event, ?int $propertyId): void
    {
        $query
            ->where('is_active', true)
            ->where(fn (Builder $scope) => $scope->whereNull('property_id')->when(
                $propertyId !== null,
                fn (Builder $scope) => $scope->orWhere('property_id', $propertyId),
            ))
            ->whereHas('events', fn (Builder $events) => $events->where('event', $event->value));
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<WebhookEndpointEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WebhookEndpointEvent::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
