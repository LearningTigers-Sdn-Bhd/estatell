<?php

namespace App\Models;

use App\Enums\AiScope;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyAiScopeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI assistant scope of a property. A scope is on only when both layers are on. A missing row is off.
 * See docs/35-ai-assistant/01-overview.md#scopes.
 *
 * @property int $id
 * @property int $property_id
 * @property AiScope $scope
 * @property bool $allowed Set by a platform admin.
 * @property bool $enabled Set by the hotel. Kept when the admin stops allowing the scope.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'scope', 'allowed', 'enabled'])]
class PropertyAiScope extends Model
{
    /** @use HasFactory<PropertyAiScopeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scope' => AiScope::class, 'allowed' => 'boolean', 'enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
