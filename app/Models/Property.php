<?php

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Services\Platform\PlatformSettings;
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
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The tenant. `code` comes from the property_code_seq sequence and never changes.
 *
 * @property int $id
 * @property int $code
 * @property string|null $document_prefix
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
class Property extends Model implements FeatureScopeSerializeable, HasMedia
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory, HasFeatures, InteractsWithMedia;

    public const string LOGO = 'logo';

    /**
     * The logo prints on PDFs. PNG or JPEG only: dompdf draws SVG poorly. New files go to the disk chosen in platform
     * settings; older files stay on their own disk.
     */
    public const array LOGO_MIME_TYPES = ['image/png', 'image/jpeg'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO)
            ->singleFile()
            ->acceptsMimeTypes(self::LOGO_MIME_TYPES)
            ->useDisk(app(PlatformSettings::class)->current()->media_disk->diskName());
    }

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
    /** @return HasMany<BoatDeparture, $this> */
    public function boatDepartures(): HasMany
    {
        return $this->hasMany(BoatDeparture::class);
    }

    public function chargeItems(): HasMany
    {
        return $this->hasMany(ChargeItem::class);
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasMany<RoomRevenuePolicy, $this> */
    public function roomRevenuePolicies(): HasMany
    {
        return $this->hasMany(RoomRevenuePolicy::class);
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

    /** @return HasMany<RegistrationCardFieldSetting, $this> */
    public function registrationCardFields(): HasMany
    {
        return $this->hasMany(RegistrationCardFieldSetting::class);
    }

    /** @return HasMany<TermsDocument, $this> */
    public function termsDocuments(): HasMany
    {
        return $this->hasMany(TermsDocument::class);
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

    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * The property's AR invoices and credit notes, also the scope of the `{invoice}` route parameter.
     *
     * @return HasMany<ArInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(ArInvoice::class);
    }

    /**
     * @return HasMany<NightAuditException, $this>
     */
    public function nightAuditExceptions(): HasMany
    {
        return $this->hasMany(NightAuditException::class);
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /** @return HasMany<PropertyNotificationSetting, $this> */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(PropertyNotificationSetting::class);
    }

    /** @return HasMany<BusinessDate, $this> */
    public function businessDates(): HasMany
    {
        return $this->hasMany(BusinessDate::class);
    }

    /** @return HasMany<NightAudit, $this> */
    public function nightAudits(): HasMany
    {
        return $this->hasMany(NightAudit::class);
    }

    /** @return HasMany<DocumentSequence, $this> */
    public function documentSequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class);
    }

    /** @return HasMany<PropertyDocumentPrefixHistory, $this> */
    public function documentPrefixHistories(): HasMany
    {
        return $this->hasMany(PropertyDocumentPrefixHistory::class);
    }

    /** @return HasOne<PropertySetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(PropertySetting::class);
    }

    /** @return HasOne<PrintSetting, $this> */
    public function printSetting(): HasOne
    {
        return $this->hasOne(PrintSetting::class);
    }

    /**
     * Stored Pennant scope, stable across class renames. Feature classes still receive the model.
     */
    public function featureScopeSerialize(): string
    {
        return "property:{$this->id}";
    }
}
