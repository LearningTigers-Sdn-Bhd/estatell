<?php

namespace App\Enums;

/**
 * A line on the registration card. The case order is the card order.
 */
enum RegistrationCardField: string
{
    case FullName = 'full_name';
    case Nationality = 'nationality';
    case IdentityDocument = 'identity_document';
    case DateOfBirth = 'date_of_birth';
    case Gender = 'gender';
    case Address = 'address';
    case Phone = 'phone';
    case Email = 'email';
    case VehiclePlate = 'vehicle_plate';
    case Remarks = 'remarks';
    case MarketingConsent = 'marketing_consent';
    case BookingNumber = 'booking_number';
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case Nights = 'nights';
    case GuestCount = 'guest_count';
    case Room = 'room';
    case RatePerNight = 'rate_per_night';
    case TotalCharges = 'total_charges';

    public function label(): string
    {
        return match ($this) {
            self::FullName => __('Full name'),
            self::Nationality => __('Nationality'),
            self::IdentityDocument => __('ID document'),
            self::DateOfBirth => __('Date of birth'),
            self::Gender => __('Gender'),
            self::Address => __('Address'),
            self::Phone => __('Phone'),
            self::Email => __('Email'),
            self::VehiclePlate => __('Vehicle plate'),
            self::Remarks => __('Remarks'),
            self::MarketingConsent => __('Marketing consent'),
            self::BookingNumber => __('Booking number'),
            self::CheckIn => __('Check-in'),
            self::CheckOut => __('Check-out'),
            self::Nights => __('Nights'),
            self::GuestCount => __('Guest count'),
            self::Room => __('Room'),
            self::RatePerNight => __('Rate per night'),
            self::TotalCharges => __('Total charges'),
        };
    }

    public function group(): RegistrationCardFieldGroup
    {
        return match ($this) {
            self::FullName,
            self::Nationality,
            self::IdentityDocument,
            self::DateOfBirth,
            self::Gender,
            self::Address,
            self::Phone,
            self::Email,
            self::VehiclePlate,
            self::Remarks,
            self::MarketingConsent => RegistrationCardFieldGroup::Guest,
            self::BookingNumber,
            self::CheckIn,
            self::CheckOut,
            self::Nights,
            self::GuestCount,
            self::Room => RegistrationCardFieldGroup::Stay,
            self::RatePerNight,
            self::TotalCharges => RegistrationCardFieldGroup::Prices,
        };
    }

    /** Stay details and prices are printed on the card. The guest does not fill them in. */
    public function isDisplayOnly(): bool
    {
        return $this->group() !== RegistrationCardFieldGroup::Guest;
    }

    /**
     * @return list<FieldVisibility>
     */
    public function allowedVisibilities(): array
    {
        return match (true) {
            $this === self::FullName => [FieldVisibility::Required],
            $this === self::MarketingConsent => [FieldVisibility::Hidden, FieldVisibility::Optional],
            $this->isDisplayOnly() => [FieldVisibility::Hidden, FieldVisibility::Required],
            default => FieldVisibility::cases(),
        };
    }

    public function defaultVisibility(): FieldVisibility
    {
        return match ($this) {
            self::FullName,
            self::Nationality,
            self::IdentityDocument,
            self::Phone => FieldVisibility::Required,
            self::DateOfBirth,
            self::Address,
            self::Email,
            self::VehiclePlate,
            self::Remarks,
            self::MarketingConsent => FieldVisibility::Optional,
            self::Gender,
            self::RatePerNight,
            self::TotalCharges => FieldVisibility::Hidden,
            self::BookingNumber,
            self::CheckIn,
            self::CheckOut,
            self::Nights,
            self::GuestCount,
            self::Room => FieldVisibility::Required,
        };
    }
}
