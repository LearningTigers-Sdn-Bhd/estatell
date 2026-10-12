<?php

namespace App\Enums;

/**
 * Roles seeded for every property, with their default permissions. The Owner role always holds every permission.
 */
enum DefaultRoleEnum: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case FrontDesk = 'front_desk';
    case Housekeeping = 'housekeeping';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Manager => __('Manager'),
            self::FrontDesk => __('Front desk'),
            self::Housekeeping => __('Housekeeping'),
            self::Finance => __('Finance'),
        };
    }

    /**
     * @return list<PermissionEnum>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => PermissionEnum::cases(),
            self::Manager => [PermissionEnum::SettingsManage, PermissionEnum::StaffView, PermissionEnum::StaffManage, PermissionEnum::RoomsView, PermissionEnum::RoomsManage, PermissionEnum::RoomsBlock, PermissionEnum::RatesView, PermissionEnum::RatesManage, PermissionEnum::RatesOverrideRestrictions, PermissionEnum::GuestsView, PermissionEnum::GuestsManage, PermissionEnum::GuestsBlacklist, PermissionEnum::GuestsViewDocuments, PermissionEnum::GuestsMerge, PermissionEnum::ReservationsView, PermissionEnum::ReservationsManage, PermissionEnum::ReservationsChangePrice, PermissionEnum::FolioPostCharge, PermissionEnum::FolioReverse, PermissionEnum::FolioTransfer, PermissionEnum::FolioDiscount, PermissionEnum::FolioDiscountOpen, PermissionEnum::FolioClose, PermissionEnum::FolioReopen, PermissionEnum::FolioPrintVoucher, PermissionEnum::PaymentRecord, PermissionEnum::PaymentRefund, PermissionEnum::CompanyView, PermissionEnum::CompanyManage, PermissionEnum::CompanyCreditOverride, PermissionEnum::CityLedgerInvoice, PermissionEnum::CityLedgerCreditNote, PermissionEnum::CityLedgerPayment, PermissionEnum::CityLedgerAllocate, PermissionEnum::RegistrationCardManageTerms, PermissionEnum::RegistrationCardSign, PermissionEnum::RegistrationCardSendLink, PermissionEnum::RegistrationCardVoid, PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut, PermissionEnum::FrontDeskCheckInWithoutCard, PermissionEnum::FrontDeskWaiveFees, PermissionEnum::FrontDeskUndoCheckIn, PermissionEnum::FrontDeskUndoCheckOut, PermissionEnum::NightAuditRun, PermissionEnum::NightAuditForce, PermissionEnum::NightAuditReceiveReport, PermissionEnum::ReportsView, PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate, PermissionEnum::HousekeepingInspect],
            self::FrontDesk => [PermissionEnum::RoomsBlock, PermissionEnum::RatesView, PermissionEnum::GuestsView, PermissionEnum::GuestsManage, PermissionEnum::GuestsViewDocuments, PermissionEnum::ReservationsView, PermissionEnum::ReservationsManage, PermissionEnum::FolioPostCharge, PermissionEnum::FolioDiscount, PermissionEnum::FolioClose, PermissionEnum::FolioPrintVoucher, PermissionEnum::PaymentRecord, PermissionEnum::CompanyView, PermissionEnum::RegistrationCardSign, PermissionEnum::RegistrationCardSendLink, PermissionEnum::FrontDeskCheckIn, PermissionEnum::FrontDeskCheckOut, PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate, PermissionEnum::HousekeepingInspect],
            self::Finance => [PermissionEnum::RatesView, PermissionEnum::GuestsView, PermissionEnum::ReservationsView, PermissionEnum::ReservationsChangePrice, PermissionEnum::FolioPostCharge, PermissionEnum::FolioReverse, PermissionEnum::FolioTransfer, PermissionEnum::FolioDiscount, PermissionEnum::FolioDiscountOpen, PermissionEnum::FolioClose, PermissionEnum::FolioReopen, PermissionEnum::FolioPrintVoucher, PermissionEnum::PaymentRecord, PermissionEnum::PaymentRefund, PermissionEnum::CompanyView, PermissionEnum::CompanyManage, PermissionEnum::CityLedgerInvoice, PermissionEnum::CityLedgerCreditNote, PermissionEnum::CityLedgerPayment, PermissionEnum::CityLedgerAllocate, PermissionEnum::ReportsView],
            self::Housekeeping => [PermissionEnum::RatesView, PermissionEnum::HousekeepingView, PermissionEnum::HousekeepingUpdate],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
