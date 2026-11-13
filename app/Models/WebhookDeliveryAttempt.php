<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try of a webhook delivery. `response_status` is empty when no response came back.
 *
 * @property int $id
 * @property int $webhook_delivery_id
 * @property int $attempt
 * @property int|null $response_status
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable $attempted_at
 */
#[Fillable(['webhook_delivery_id', 'attempt', 'response_status', 'error', 'duration_ms', 'attempted_at'])]
class WebhookDeliveryAttempt extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }
}
