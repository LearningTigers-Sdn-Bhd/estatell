<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\CompanyType;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A corporate, travel agent or government body that stays now and pays later through the city ledger.
 * See docs/15-city-ledger/01-companies.md.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property CompanyType $type
 * @property string|null $brn
 * @property string|null $tin
 * @property string|null $sst_registration_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $billing_address_line1
 * @property string|null $billing_address_line2
 * @property string|null $billing_city
 * @property string|null $billing_state_code
 * @property string|null $billing_postal_code
 * @property string|null $billing_country_code
 * @property string|null $credit_limit
 * @property int|null $payment_terms_days
 * @property bool $direct_bill_enabled
 * @property int|null $portal_cancel_cutoff_days
 * @property bool $profile_managed_by_company Set by the company in the portal: hotel staff may no longer edit the tax numbers and billing address.
 * @property CompanyStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name', 'type', 'brn', 'tin', 'sst_registration_number', 'contact_email', 'contact_phone',
    'billing_address_line1', 'billing_address_line2', 'billing_city', 'billing_state_code',
    'billing_postal_code', 'billing_country_code', 'credit_limit', 'payment_terms_days',
    'direct_bill_enabled', 'portal_cancel_cutoff_days', 'status', 'profile_managed_by_company',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CompanyType::class,
            'status' => CompanyStatus::class,
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'direct_bill_enabled' => 'boolean',
            'portal_cancel_cutoff_days' => 'integer',
            'profile_managed_by_company' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The plans that list this company, with the Front desk (`at_desk`) and Portal (`in_portal`) switches of its row.
     *
     * @return BelongsToMany<RatePlan, $this>
     */
    public function ratePlans(): BelongsToMany
    {
        return $this->belongsToMany(RatePlan::class, 'company_rate_plans')->withPivot(['at_desk', 'in_portal'])->withTimestamps();
    }

    /**
     * Every portal login link the company had, newest first.
     *
     * @return HasMany<CompanyUser, $this>
     */
    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * The company's open portal login link (Pending or Active), if any.
     *
     * @return HasOne<CompanyUser, $this>
     */
    public function portalLink(): HasOne
    {
        return $this->hasOne(CompanyUser::class)->open();
    }

    /**
     * The payments the company made against its city ledger.
     *
     * @return HasMany<ArPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ArPayment::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Name order, ignoring case.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw('lower(name)')->orderBy('id');
    }
}
