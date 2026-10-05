<?php

namespace App\Models;

use App\Enums\FieldVisibility;
use App\Enums\RegistrationCardField;
use Database\Factories\RegistrationCardFieldSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How one registration card field behaves for a property.
 *
 * @property int $id
 * @property int $property_id
 * @property RegistrationCardField $field
 * @property FieldVisibility $visibility
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['field', 'visibility', 'position'])]
class RegistrationCardFieldSetting extends Model
{
    /** @use HasFactory<RegistrationCardFieldSettingFactory> */
    use HasFactory;

    protected $table = 'registration_card_fields';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'field' => RegistrationCardField::class,
            'visibility' => FieldVisibility::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param Builder<RegistrationCardFieldSetting> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position');
    }
}
