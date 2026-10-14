<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TermsDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of registration terms. Its text lives in versions, one line per language.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'is_default'])]
class TermsDocument extends Model
{
    /** @use HasFactory<TermsDocumentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<RegistrationCardTerms, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RegistrationCardTerms::class);
    }

    /** @return HasMany<RoomType, $this> */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }
}
