<?php

namespace App\Models;

use App\Enums\TaxRateType;
use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A property tax rule and its posting identity.
 *
 * @property int $id
 * @property int $property_id
 * @property int $transaction_code_id
 * @property string $name
 * @property string $code
 * @property TaxRateType $rate_type
 * @property string $rate
 * @property bool $is_inclusive
 * @property bool $foreign_guests_only
 * @property bool $collect_at_check_in
 * @property string|null $registration_number
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transaction_code_id', 'name', 'code', 'rate_type', 'rate', 'is_inclusive',
    'foreign_guests_only', 'collect_at_check_in', 'registration_number', 'enabled',
])]
class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rate_type' => TaxRateType::class,
            'rate' => 'decimal:2',
            'is_inclusive' => 'boolean',
            'foreign_guests_only' => 'boolean',
            'collect_at_check_in' => 'boolean',
            'enabled' => 'boolean',
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

    /** @return BelongsToMany<TransactionCode, $this> */
    public function applicableTransactionCodes(): BelongsToMany
    {
        return $this->belongsToMany(TransactionCode::class, 'tax_transaction_code');
    }

    /** @param Builder<Tax> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }
}
