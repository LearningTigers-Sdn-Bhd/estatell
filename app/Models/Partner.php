<?php

namespace App\Models;

use App\Enums\PartnerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reseller or consultant who brings properties to estatell. `code_prefix` and `signup_code` are set by
 * CreatePartner and never mass assigned.
 *
 * @property int $id
 * @property string $name
 * @property string $code_prefix
 * @property string $signup_code
 * @property string|null $signup_message_template
 * @property PartnerStatus $status
 * @property bool $can_create_properties
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'status', 'can_create_properties', 'suspended_at', 'signup_message_template'])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PartnerStatus::class,
            'can_create_properties' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasMany<PartnerInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(PartnerInvitation::class);
    }
}
