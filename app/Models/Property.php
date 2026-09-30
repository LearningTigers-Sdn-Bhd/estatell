<?php

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Concerns\HasFeatures;
use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/**
 * The tenant. `code` comes from the property_code_seq sequence and never changes.
 *
 * @property int $id
 * @property int $code
 * @property string $name
 * @property PropertyType|null $type
 * @property PropertyStatus $status
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state_code
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $registration_number
 * @property string|null $tin
 * @property Carbon|null $onboarding_started_at
 * @property Carbon|null $went_live_at
 * @property Carbon|null $suspended_at
 * @property PropertyStatus|null $suspended_from_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'type', 'status',
    'address_line1', 'address_line2', 'city', 'state_code', 'postal_code', 'country_code',
    'contact_email', 'contact_phone', 'registration_number', 'tin',
    'onboarding_started_at', 'went_live_at', 'suspended_at', 'suspended_from_status',
])]
class Property extends Model implements FeatureScopeSerializeable
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, HasFeatures;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => 'integer',
            'type' => PropertyType::class,
            'status' => PropertyStatus::class,
            'onboarding_started_at' => 'datetime',
            'went_live_at' => 'datetime',
            'suspended_at' => 'datetime',
            'suspended_from_status' => PropertyStatus::class,
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<StaffInvitation, $this>
     */
    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }

    /**
     * @return HasMany<RoomType, $this>
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    /** @return HasMany<TransactionCode, $this> */
    public function transactionCodes(): HasMany
    {
        return $this->hasMany(TransactionCode::class);
    }

    /** @return HasMany<ChargeItem, $this> */
    public function chargeItems(): HasMany
    {
        return $this->hasMany(ChargeItem::class);
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasMany<Tax, $this> */
    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    /** @return HasMany<PropertyAgeGroup, $this> */
    public function ageGroups(): HasMany
    {
        return $this->hasMany(PropertyAgeGroup::class);
    }

    /** @return HasMany<RatePlan, $this> */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /** @return HasMany<MealPeriod, $this> */
    public function mealPeriods(): HasMany
    {
        return $this->hasMany(MealPeriod::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * @return HasMany<RoomGroup, $this>
     */
    public function roomGroups(): HasMany
    {
        return $this->hasMany(RoomGroup::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return HasManyThrough<Bed, Room, $this>
     */
    public function beds(): HasManyThrough
    {
        return $this->hasManyThrough(Bed::class, Room::class);
    }

    /**
     * @return HasMany<RoomBlock, $this>
     */
    public function roomBlocks(): HasMany
    {
        return $this->hasMany(RoomBlock::class);
    }

    /**
     * @return HasMany<RoomStatus, $this>
     */
    public function roomStatuses(): HasMany
    {
        return $this->hasMany(RoomStatus::class);
    }

    /** @return HasMany<PropertyNotificationSetting, $this> */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(PropertyNotificationSetting::class);
    }

    /** @return HasOne<PropertySetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(PropertySetting::class);
    }

    /**
     * Stored Pennant scope, stable across class renames. Feature classes still receive the model.
     */
    public function featureScopeSerialize(): string
    {
        return "property:{$this->id}";
    }
}
