<?php

namespace App\Models;

use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use Database\Factories\TransactionCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Classifies a money line for reporting, tax rules and accounting exports.
 *
 * @property int $id
 * @property int $property_id
 * @property string|null $system_key
 * @property string $code
 * @property string $name
 * @property TransactionKind $kind
 * @property TransactionCategory $category
 * @property string|null $gl_account_code
 * @property bool $is_system
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['system_key', 'code', 'name', 'kind', 'category', 'gl_account_code', 'is_system', 'active'])]
class TransactionCode extends Model
{
    /** @use HasFactory<TransactionCodeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'category' => TransactionCategory::class,
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<ChargeItem, $this> */
    public function chargeItems(): HasMany
    {
        return $this->hasMany(ChargeItem::class);
    }

    /** @return HasOne<Tax, $this> */
    public function postingTax(): HasOne
    {
        return $this->hasOne(Tax::class);
    }

    /** @return BelongsToMany<Tax, $this> */
    public function applicableTaxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'tax_transaction_code');
    }

    /** @param Builder<TransactionCode> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }
}
