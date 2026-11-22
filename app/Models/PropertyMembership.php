<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff user's access to one property. The role there lives in the permission tables (teams keyed on property).
 * Deactivated at one property, the user may still open the others. Created with the user (invitation, temp password,
 * onboarding) for the home property, and by `GrantPropertyAccess` for another property of the group.
 * See docs/03-staff/04-property-groups.md.
 *
 * @property int $id
 * @property int $property_id
 * @property int $user_id
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['property_id', 'deactivated_at'])]
class PropertyMembership extends Model
{
    /** @use HasFactory<PropertyMembershipFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<PropertyMembership> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }
}
