<?php

namespace App\Models;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A property-configured discount that staff may apply on a folio or at booking.
 *
 * @property int $id
 * @property int $property_id
 * @property int $transaction_code_id
 * @property string $name
 * @property DiscountType $type
 * @property string|null $value
 * @property bool $allow_override
 * @property DiscountScope $scope
 * @property int $position
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'value', 'allow_override', 'scope', 'position', 'active'])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'decimal:2',
            'allow_override' => 'boolean',
            'scope' => DiscountScope::class,
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<TransactionCode, $this> */
    public function transactionCode(): BelongsTo
    {
        return $this->belongsTo(TransactionCode::class);
    }

    /**
     * The charge codes a Selected discount targets.
     *
     * @return BelongsToMany<TransactionCode, $this>
     */
    public function selectedTransactionCodes(): BelongsToMany
    {
        return $this->belongsToMany(TransactionCode::class, 'discount_transaction_code');
    }

    /** @param Builder<Discount> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<Discount> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
