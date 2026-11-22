<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $property_id The home property: where a staff user was invited. Null for admins, partner and company users.
 * @property int|null $last_property_id The property the staff user opened last, where sign-in lands.
 * @property int|null $partner_id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $temporary_password
 * @property CarbonImmutable|null $temporary_password_expires_at
 * @property CarbonImmutable|null $temporary_password_shared_at
 * @property bool $must_change_password
 * @property bool $email_device_check
 * @property bool $is_admin
 * @property bool $is_company_user
 * @property CarbonImmutable|null $deactivated_at Set when the user may sign in nowhere; for staff, when no membership is active.
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Role> $roles
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'temporary_password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'temporary_password' => 'encrypted',
            'temporary_password_expires_at' => 'datetime',
            'temporary_password_shared_at' => 'datetime',
            'must_change_password' => 'boolean',
            'email_device_check' => 'boolean',
            'is_admin' => 'boolean',
            'is_company_user' => 'boolean',
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The property a staff user belongs to. Null for platform admins.
     *
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The property the staff user opened last.
     *
     * @return BelongsTo<Property, $this>
     */
    public function lastProperty(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'last_property_id');
    }

    /**
     * The properties the staff user may open (active) or was turned off at (deactivated).
     *
     * @return HasMany<PropertyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(PropertyMembership::class);
    }

    /**
     * Staff who have a membership at the property, active or not. See docs/03-staff/04-property-groups.md.
     *
     * @param  Builder<User>  $query
     */
    public function scopeMemberOf(Builder $query, Property|int $property): void
    {
        $query->whereHas('memberships', fn (Builder $memberships) => $memberships->where('property_id', $property instanceof Property ? $property->id : $property));
    }

    /**
     * Staff who may open the property now: their membership there is not deactivated, nor their account.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActiveMemberOf(Builder $query, Property|int $property): void
    {
        $query->whereNull('users.deactivated_at')->whereHas('memberships', fn (Builder $memberships) => $memberships->where('property_id', $property instanceof Property ? $property->id : $property)->whereNull('deactivated_at'));
    }

    /**
     * The partner a partner user signs in for. Null for admins and staff.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * The company links of a company user, at any property.
     *
     * @return HasMany<CompanyUser, $this>
     */
    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /** @return HasMany<UserNotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(UserNotificationPreference::class);
    }
}
