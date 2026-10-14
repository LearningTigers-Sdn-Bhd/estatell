<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A guest of one property. Guests are never shared across properties.
 *
 * @property int $id
 * @property int $property_id
 * @property string $full_name
 * @property string $name_key
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $nationality_code
 * @property CarbonImmutable|null $date_of_birth
 * @property Gender|null $gender
 * @property Locale|null $preferred_locale
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property bool $is_vip
 * @property CarbonImmutable|null $blacklisted_at
 * @property string|null $blacklist_reason
 * @property CarbonImmutable|null $marketing_consent_at
 * @property int|null $merged_into_id
 * @property CarbonImmutable|null $anonymized_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'full_name', 'email', 'phone', 'nationality_code', 'date_of_birth', 'gender', 'preferred_locale',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
])]
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'preferred_locale' => Locale::class,
            'is_vip' => 'boolean',
            'blacklisted_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<GuestNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(GuestNote::class);
    }

    /**
     * @return HasMany<GuestIdentityDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GuestIdentityDocument::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Guests that were merged into another guest are hidden everywhere.
     *
     * @param  Builder<Guest>  $query
     */
    public function scopeNotMerged(Builder $query): void
    {
        $query->whereNull('guests.merged_into_id');
    }

    /**
     * @param  Builder<Guest>  $query
     */
    public function scopeBlacklisted(Builder $query): void
    {
        $query->whereNotNull('guests.blacklisted_at');
    }

    /**
     * Guests whose name, email or phone contains the text. Phone numbers are compared by their digits.
     * Shared by the guest list and the reservation search.
     *
     * @param  Builder<Guest>  $query
     */
    public function scopeMatchingText(Builder $query, string $text): void
    {
        $text = trim($text);

        $query->where(function (Builder $query) use ($text): void {
            $query->whereLike('guests.full_name', "%{$text}%")
                ->orWhereLike('guests.email', "%{$text}%")
                ->orWhereLike('guests.phone', '%'.preg_replace('/[\s()-]+/', '', $text).'%');
        });
    }
}
