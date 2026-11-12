<?php

namespace App\Models;

use App\Enums\GuestEmail;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyGuestEmailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A property's own subject and message for one guest email. See `GuestEmail` for the default text.
 *
 * @property int $id
 * @property int $property_id
 * @property GuestEmail $email
 * @property string $subject
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Property $property
 */
#[Fillable(['property_id', 'email', 'subject', 'body'])]
class PropertyGuestEmail extends Model
{
    /** @use HasFactory<PropertyGuestEmailFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => GuestEmail::class,
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
