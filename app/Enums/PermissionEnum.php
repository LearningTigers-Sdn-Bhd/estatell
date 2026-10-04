<?php

namespace App\Enums;

/**
 * Every staff permission. Each module adds its own cases and maps them in DefaultRoleEnum.
 * SyncPermissions turns new cases into permission rows. See docs/03-staff/02-roles-and-permissions.md.
 */
enum PermissionEnum: string
{
    case SettingsManage = 'settings.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';
    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';
    case RoomsBlock = 'rooms.block';
    case NotificationManage = 'notification.manage';
    case RatesView = 'rates.view';
    case RatesManage = 'rates.manage';
    case RatesOverrideRestrictions = 'rates.override-restrictions';
    case GuestsView = 'guests.view';
    case GuestsManage = 'guests.manage';
    case GuestsBlacklist = 'guests.blacklist';
    case GuestsViewDocuments = 'guests.view-documents';
    case GuestsMerge = 'guests.merge';
    case GuestsAnonymize = 'guests.anonymize';
    case ReservationsView = 'reservations.view';
    case ReservationsManage = 'reservations.manage';
    case FolioPostCharge = 'folio.post-charge';
    case FolioReverse = 'folio.reverse';
    case FolioTransfer = 'folio.transfer';
    case FolioDiscount = 'folio.discount';
    case FolioDiscountOpen = 'folio.discount-open';
    case FolioClose = 'folio.close';
    case FolioReopen = 'folio.reopen';
    case FolioPrintVoucher = 'folio.print-voucher';
    case PaymentRecord = 'payment.record';
    case PaymentRefund = 'payment.refund';
    case CompanyView = 'company.view';
    case CompanyManage = 'company.manage';
    case CityLedgerInvoice = 'city-ledger.invoice';
    case CityLedgerCreditNote = 'city-ledger.credit-note';

    public function label(): string
    {
        return match ($this) {
            self::SettingsManage => __('Manage property settings'),
            self::StaffView => __('View staff'),
            self::StaffManage => __('Manage staff'),
            self::RolesManage => __('Manage roles'),
            self::RoomsView => __('View rooms and room types'),
            self::RoomsManage => __('Manage rooms and room types'),
            self::RoomsBlock => __('Block rooms and beds'),
            self::NotificationManage => __('Manage notification settings'),
            self::RatesView => __('View the rate calendar'),
            self::RatesManage => __('Edit rates and restrictions'),
            self::RatesOverrideRestrictions => __('Book past rate restrictions'),
            self::GuestsView => __('View guests'),
            self::GuestsManage => __('Add and edit guests'),
            self::GuestsBlacklist => __('Blacklist guests'),
            self::GuestsViewDocuments => __('View and manage guest ID documents'),
            self::GuestsMerge => __('Merge duplicate guests'),
            self::GuestsAnonymize => __('Anonymize guests'),
            self::ReservationsView => __('View reservations'),
            self::ReservationsManage => __('Manage reservations'),
            self::FolioPostCharge => __('Post charges to folios'),
            self::FolioReverse => __('Reverse folio lines'),
            self::FolioTransfer => __('Transfer folio lines and manage routing'),
            self::FolioDiscount => __('Apply and remove folio discounts'),
            self::FolioDiscountOpen => __('Apply discounts with an entered amount'),
            self::FolioClose => __('Close folios'),
            self::FolioReopen => __('Reopen closed folios'),
            self::FolioPrintVoucher => __('Print folio vouchers'),
            self::PaymentRecord => __('Record payments and download receipts'),
            self::PaymentRefund => __('Refund payments'),
            self::CompanyView => __('View companies'),
            self::CompanyManage => __('Manage companies'),
            self::CityLedgerInvoice => __('Settle folios to City ledger and void AR invoices'),
            self::CityLedgerCreditNote => __('Issue and void AR credit notes'),
        };
    }

    /**
     * The heading the permission sits under on the role page.
     */
    public function group(): string
    {
        return match ($this) {
            self::SettingsManage => __('Property'),
            self::StaffView, self::StaffManage, self::RolesManage => __('Team'),
            self::RoomsView, self::RoomsManage, self::RoomsBlock => __('Rooms'),
            self::NotificationManage => __('System'),
            self::RatesView, self::RatesManage, self::RatesOverrideRestrictions => __('Rates'),
            self::GuestsView, self::GuestsManage, self::GuestsBlacklist, self::GuestsViewDocuments, self::GuestsMerge, self::GuestsAnonymize => __('Guests'),
            self::ReservationsView, self::ReservationsManage => __('Reservations'),
            self::FolioPostCharge, self::FolioReverse, self::FolioTransfer, self::FolioDiscount, self::FolioDiscountOpen, self::FolioClose, self::FolioReopen, self::FolioPrintVoucher => __('Folio'),
            self::PaymentRecord, self::PaymentRefund => __('Payments'),
            self::CompanyView, self::CompanyManage, self::CityLedgerInvoice, self::CityLedgerCreditNote => __('City ledger'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $permission): array => ['value' => $permission->value, 'label' => $permission->label()],
            self::cases(),
        );
    }
}
