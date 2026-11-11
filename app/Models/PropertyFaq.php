<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyFaqFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question guests often ask a property, with its answer.
 *
 * @property int $id
 * @property int $property_id
 * @property string $question
 * @property string $answer
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'question', 'answer', 'position'])]
class PropertyFaq extends Model
{
    /** @use HasFactory<PropertyFaqFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
