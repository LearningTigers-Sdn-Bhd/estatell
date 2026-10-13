<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use App\Models\User;
use Spatie\LaravelData\Data;

class AdminPropertyData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public BadgeData $status,
        public ?string $type,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $registrationNumber,
        public ?string $tin,
        public string $createdAt,
        public ?string $wentLiveAt,
        public ?string $suspendedAt,
        public int $staffCount,
        public ?string $ownerName,
        public ?string $ownerEmail,
    ) {}

    /**
     * Expects `users_count` from `loadCount('users')`.
     */
    public static function fromModel(Property $property, ?User $owner): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            status: BadgeData::fromStatus($property->status),
            type: $property->type?->label(),
            addressLine1: $property->address_line1,
            addressLine2: $property->address_line2,
            city: $property->city,
            stateCode: $property->state_code,
            postalCode: $property->postal_code,
            countryCode: $property->country_code,
            contactEmail: $property->contact_email,
            contactPhone: $property->contact_phone,
            registrationNumber: $property->registration_number,
            tin: $property->tin,
            createdAt: $property->created_at->toDateString(),
            wentLiveAt: $property->went_live_at?->toDateString(),
            suspendedAt: $property->suspended_at?->toDateString(),
            staffCount: (int) $property->getAttribute('users_count'),
            ownerName: $owner?->name,
            ownerEmail: $owner?->email,
        );
    }
}
