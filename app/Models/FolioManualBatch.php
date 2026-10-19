<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FolioManualBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One manual posting on a folio: the reason a manager gave and the lines it wrote. It is never updated or deleted,
 * like the ledger. `idempotency_key` is the page form's UUID, so a repeated submit finds the same batch.
 * See docs/13-folio/08-manual-folio.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $folio_id
 * @property string $reason
 * @property int $user_id
 * @property string $idempotency_key
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['reason', 'idempotency_key'])]
class FolioManualBatch extends Model
{
    /** @use HasFactory<FolioManualBatchFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<FolioLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(FolioLine::class, 'manual_batch_id');
    }
}
