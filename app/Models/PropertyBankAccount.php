<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyBankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account the property takes transfers into. Companies see the available ones in the portal, and documents print
 * them. See docs/06-catalogues/07-bank-accounts.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $bank_name
 * @property string $account_name
 * @property string $account_number
 * @property string|null $swift_code
 * @property int $position
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['bank_name', 'account_name', 'account_number', 'swift_code', 'position', 'active'])]
class PropertyBankAccount extends Model
{
    /** @use HasFactory<PropertyBankAccountFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param Builder<PropertyBankAccount> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** @param Builder<PropertyBankAccount> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
