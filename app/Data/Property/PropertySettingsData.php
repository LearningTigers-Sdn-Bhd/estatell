<?php

namespace App\Data\Property;

use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\RegistrationCardScope;
use App\Enums\WeekendNights;
use App\Models\PropertySetting;
use Spatie\LaravelData\Data;

class PropertySettingsData extends Data
{
    public function __construct(
        public CurrencyData $currency,
        public string $timeZone,
        public Locale $defaultLocale,
        public string $checkInTime,
        public string $checkOutTime,
        public int $arrivalGraceMinutes,
        public bool $autoAssignRooms,
        public string $auditTime,
        public bool $autoAuditEnabled,
        public MissedArrivalPolicy $missedArrivalPolicy,
        public OverdueDeparturePolicy $overdueDeparturePolicy,
        public WeekendNights $weekendNights,
        public RegistrationCardScope $registrationCardScope,
        public bool $cardRequiredForCheckIn,
        public ?int $idDocumentRetentionDays,
        public string $securityDepositAmount,
        public bool $allowSupportAccess,
        public bool $allowSupportMoneyCorrections,
        public int $migrationWindowDays,
        public bool $currencyAndTimeZoneLocked,
    ) {}

    public static function fromModel(PropertySetting $settings, bool $currencyAndTimeZoneLocked): self
    {
        return new self(
            currency: CurrencyData::fromCurrency($settings->currency),
            timeZone: $settings->time_zone,
            defaultLocale: $settings->default_locale,
            checkInTime: substr($settings->check_in_time, 0, 5),
            checkOutTime: substr($settings->check_out_time, 0, 5),
            arrivalGraceMinutes: $settings->arrival_grace_minutes,
            autoAssignRooms: $settings->auto_assign_rooms,
            auditTime: substr($settings->audit_time, 0, 5),
            autoAuditEnabled: $settings->auto_audit_enabled,
            missedArrivalPolicy: $settings->missed_arrival_policy,
            overdueDeparturePolicy: $settings->overdue_departure_policy,
            weekendNights: $settings->weekend_nights,
            registrationCardScope: $settings->registration_card_scope,
            cardRequiredForCheckIn: $settings->card_required_for_check_in,
            idDocumentRetentionDays: $settings->id_document_retention_days,
            securityDepositAmount: $settings->security_deposit_amount,
            allowSupportAccess: $settings->allow_support_access,
            allowSupportMoneyCorrections: $settings->allow_support_money_corrections,
            migrationWindowDays: $settings->migration_window_days,
            currencyAndTimeZoneLocked: $currencyAndTimeZoneLocked,
        );
    }
}
