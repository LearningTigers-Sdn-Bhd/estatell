<?php

namespace App\Models;

use App\Enums\DefaultRoleEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A staff role in one property. `default_role` marks the seeded roles; custom roles leave it null.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property string $guard_name
 * @property DefaultRoleEnum|null $default_role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_role' => DefaultRoleEnum::class,
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
