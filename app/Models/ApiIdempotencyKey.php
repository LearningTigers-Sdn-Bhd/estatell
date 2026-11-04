<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The first answer to one `Idempotency-Key` on one path, kept for 24 hours so a retry gets the same answer.
 * See docs/28-api-access/03-kiosk-check-in-endpoints.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $kiosk_id
 * @property string $key
 * @property string $path
 * @property int $status
 * @property string $body The JSON text of the first answer.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'path', 'status', 'body'])]
class ApiIdempotencyKey extends Model
{
    /** How long a stored answer is replayed. */
    public const int RETENTION_HOURS = 24;

    /**
     * @return BelongsTo<Kiosk, $this>
     */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }
}
