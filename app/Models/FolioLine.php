<?php

namespace App\Models;

use App\Enums\TransactionKind;
use Carbon\CarbonImmutable;
use Database\Factories\FolioLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the immutable folio ledger. `amount` is signed: charges and taxes are positive, payments negative.
 * A database trigger rejects every UPDATE and DELETE, so a correction is a new line with `reversal_of_id`.
 * It has no `property_id` and no `updated_at`: the folio scopes it. `source_type` and `source_id` name the
 * record that caused the posting, and `description` and `kind` are copied at posting time.
 *
 * @property int $id
 * @property int $folio_id
 * @property int $transaction_code_id
 * @property TransactionKind $kind
 * @property string $description
 * @property int $quantity
 * @property string $unit_amount
 * @property string $amount
 * @property-read CarbonImmutable $posting_date
 * @property-write CarbonImmutable|string $posting_date
 * @property-read CarbonImmutable $service_date
 * @property-write CarbonImmutable|string $service_date
 * @property int|null $manual_batch_id
 * @property bool $migrated True for a line copied from the old system by the old folio page.
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $parent_line_id
 * @property int|null $reversal_of_id
 * @property string|null $transfer_group_id
 * @property string $idempotency_key
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'folio_id', 'transaction_code_id', 'kind', 'description', 'quantity', 'unit_amount', 'amount', 'posting_date', 'service_date',
    'source_type', 'source_id', 'parent_line_id', 'reversal_of_id', 'transfer_group_id', 'idempotency_key', 'user_id',
])]
class FolioLine extends Model
{
    /** @use HasFactory<FolioLineFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'posting_date' => 'date',
            'service_date' => 'date',
            'migrated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Folio, $this>
     */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /**
     * @return BelongsTo<FolioManualBatch, $this>
     */
    public function manualBatch(): BelongsTo
    {
        return $this->belongsTo(FolioManualBatch::class, 'manual_batch_id');
    }

    /**
     * @return BelongsTo<TransactionCode, $this>
     */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class);
    }

    /**
     * The unit, unit night or add-on that caused the line.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_line_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_line_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The payment or refund that owns this line, if any. Such a line is corrected only by a refund.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
