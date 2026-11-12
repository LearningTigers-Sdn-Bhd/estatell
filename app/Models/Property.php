<?php

namespace App\Models;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Laravel\Pennant\Concerns\HasFeatures;
use Laravel\Pennant\Contracts\FeatureScopeSerializeable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The tenant. `code` is issued by IssuePropertyCode (prefix plus number, for example ES1101) and never changes.
 *
 * @property int $id
 * @property int|null $partner_id
 * @property string $code
 * @property string|null $slug
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
 * @property string|null $legal_name
 * @property string|null $registration_number
 * @property string|null $tin
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $google_maps_url
 * @property CarbonImmutable|null $onboarding_started_at
 * @property CarbonImmutable|null $onboarding_last_activity_at
 * @property int $onboarding_reminder_stage
 * @property CarbonImmutable|null $went_live_at
 * @property CarbonImmutable|null $suspended_at
 * @property PropertyStatus|null $suspended_from_status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PropertySetting|null $settings
 * @property-read (Pivot&object{display_name: string|null})|null $pivot Set when loaded through NearbyPlace::properties(); holds this property's name for the place.
 */
#[Fillable([
    'name', 'type', 'status',
    'address_line1', 'address_line2', 'city', 'state_code', 'postal_code', 'country_code',
    'contact_email', 'contact_phone', 'legal_name', 'registration_number', 'tin',
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

    public const string PHOTOS = 'photos';

    public const int PHOTO_LIMIT = 20;

    public const int PHOTO_MAX_KB = 5120;

    public const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const array PHOTO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Album photos are shown to guests, so they live on the public media disk.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO)
            ->singleFile()
            ->acceptsMimeTypes(self::LOGO_MIME_TYPES)
            ->useDisk(app(PlatformSettings::class)->current()->media_disk->diskName());

        $this->addMediaCollection(self::PHOTOS)
            ->acceptsMimeTypes(self::PHOTO_MIME_TYPES)
            ->useDisk(config('filesystems.public_media'));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->performOnCollections(self::PHOTOS)->nonQueued()->width(400);
        $this->addMediaConversion('web')->performOnCollections(self::PHOTOS)->nonQueued()->width(1600);
    }

    /**
     * The partner who brought the property. Null for direct (ES) properties.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
            'status' => PropertyStatus::class,
            'onboarding_started_at' => 'datetime',
            'onboarding_last_activity_at' => 'datetime',
            'onboarding_reminder_stage' => 'integer',
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
     * @return HasMany<PayoutVoucher, $this>
     */
    public function payoutVouchers(): HasMany
    {
        return $this->hasMany(PayoutVoucher::class);
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<PropertyCommissionPeriod, $this>
     */
    public function commissionPeriods(): HasMany
    {
        return $this->hasMany(PropertyCommissionPeriod::class);
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

    /** @return HasMany<BoatDeparture, $this> */
    public function boatDepartures(): HasMany
    {
        return $this->hasMany(BoatDeparture::class);
    }

    /** @return HasMany<Boat, $this> */
    public function boats(): HasMany
    {
        return $this->hasMany(Boat::class);
    }

    /**
     * The boat crew. Named after the `{crewMember}` route parameter, so scoped route bindings find it.
     *
     * @return HasMany<BoatCrewMember, $this>
     */
    public function crewMembers(): HasMany
    {
        return $this->hasMany(BoatCrewMember::class);
    }

    /** @return HasMany<BookingSource, $this> */
    public function bookingSources(): HasMany
    {
        return $this->hasMany(BookingSource::class);
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

    /** @return HasMany<PropertyLicence, $this> */
    public function licences(): HasMany
    {
        return $this->hasMany(PropertyLicence::class);
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

    /** @return HasMany<PropertyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PropertyBankAccount::class);
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
     * @return HasMany<PropertyOtaCredential, $this>
     */
    public function otaCredentials(): HasMany
    {
        return $this->hasMany(PropertyOtaCredential::class);
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

    /** @return HasMany<Kiosk, $this> */
    public function kiosks(): HasMany
    {
        return $this->hasMany(Kiosk::class);
    }

    /** @return HasMany<GuestRequest, $this> */
    public function guestRequests(): HasMany
    {
        return $this->hasMany(GuestRequest::class);
    }

    /** @return HasMany<PropertyWifiNetwork, $this> */
    public function wifiNetworks(): HasMany
    {
        return $this->hasMany(PropertyWifiNetwork::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<PropertyFaq, $this> */
    public function faqs(): HasMany
    {
        return $this->hasMany(PropertyFaq::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<PropertyInfoTopic, $this> */
    public function infoTopics(): HasMany
    {
        return $this->hasMany(PropertyInfoTopic::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasOne<PropertyTransportDetail, $this> */
    public function transportDetail(): HasOne
    {
        return $this->hasOne(PropertyTransportDetail::class);
    }

    /** @return BelongsToMany<NearbyPlace, $this> */
    public function nearbyPlaces(): BelongsToMany
    {
        return $this->belongsToMany(NearbyPlace::class, 'property_nearby_places')->withPivot(['display_name', 'description', 'position', 'active'])->withTimestamps()->orderByPivot('position');
    }

    /** @return BelongsToMany<Amenity, $this> */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'property_amenities')->withPivot(['location', 'opening_hours', 'fee_information', 'reservation_required', 'reservation_instructions', 'guest_notes']);
    }

    /** @return HasOne<PropertyBookingSite, $this> */
    public function bookingSite(): HasOne
    {
        return $this->hasOne(PropertyBookingSite::class);
    }

    /**
     * @return HasMany<BookingReturnAddress, $this>
     */
    public function bookingReturnAddresses(): HasMany
    {
        return $this->hasMany(BookingReturnAddress::class);
    }

    /** @return HasOne<DoorLockConnection, $this> */
    public function doorLockConnection(): HasOne
    {
        return $this->hasOne(DoorLockConnection::class);
    }

    /** @return HasMany<DoorPasscode, $this> */
    public function doorPasscodes(): HasMany
    {
        return $this->hasMany(DoorPasscode::class);
    }

    /** @return HasMany<KeyportDevice, $this> */
    public function keyportDevices(): HasMany
    {
        return $this->hasMany(KeyportDevice::class);
    }

    /** @return HasMany<KeyportJob, $this> */
    public function keyportJobs(): HasMany
    {
        return $this->hasMany(KeyportJob::class);
    }

    /** @return HasMany<DoorLock, $this> */
    public function doorLocks(): HasMany
    {
        return $this->hasMany(DoorLock::class);
    }

    /** @return HasOne<AccountingConnection, $this> */
    public function accountingConnection(): HasOne
    {
        return $this->hasOne(AccountingConnection::class);
    }

    /** @return HasMany<AccountingSync, $this> */
    public function accountingSyncs(): HasMany
    {
        return $this->hasMany(AccountingSync::class);
    }

    /** @return HasOne<ChannelProperty, $this> */
    public function channelProperty(): HasOne
    {
        return $this->hasOne(ChannelProperty::class);
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
