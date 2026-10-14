<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyLicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business licence or registration of the property, such as a local council licence or a MOTAC registration.
 *
 * @property int $id
 * @property int $property_id
 * @property string $issuer
 * @property string $number
 * @property CarbonImmutable|null $expires_on
 * @property bool $show_on_documents
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['issuer', 'number', 'expires_on', 'show_on_documents', 'position'])]
class PropertyLicence extends Model
{
    /** @use HasFactory<PropertyLicenceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'show_on_documents' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param Builder<PropertyLicence> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
