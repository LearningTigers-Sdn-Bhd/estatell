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
    case ReservationsChangePrice = 'reservations.change-price';
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
    case CompanyCreditOverride = 'company.credit-override';
    case CityLedgerInvoice = 'city-ledger.invoice';
    case CityLedgerCreditNote = 'city-ledger.credit-note';
    case CityLedgerPayment = 'city-ledger.payment';
    case CityLedgerAllocate = 'city-ledger.allocate';
    case RegistrationCardManageTerms = 'registration-card.manage-terms';
    case RegistrationCardSign = 'registration-card.sign';
    case RegistrationCardSendLink = 'registration-card.send-link';
    case RegistrationCardVoid = 'registration-card.void';
    case FrontDeskCheckIn = 'front-desk.check-in';
    case FrontDeskCheckOut = 'front-desk.check-out';
    case FrontDeskCheckInWithoutCard = 'front-desk.check-in-without-card';
    case FrontDeskWaiveFees = 'front-desk.waive-fees';
    case FrontDeskUndoCheckIn = 'front-desk.undo-check-in';
    case FrontDeskUndoCheckOut = 'front-desk.undo-check-out';
    case NightAuditRun = 'night-audit.run';
    case NightAuditForce = 'night-audit.force';
    case NightAuditReceiveReport = 'night-audit.receive-report';
    case ReportsView = 'reports.view';
    case HousekeepingView = 'housekeeping.view';
    case HousekeepingUpdate = 'housekeeping.update';
    case HousekeepingInspect = 'housekeeping.inspect';

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
            self::ReservationsChangePrice => __('Change the price of booked nights'),
            self::FolioPostCharge => __('Post charges to folios'),
            self::FolioReverse => __('Reverse folio lines'),
            self::FolioTransfer => __('Transfer folio lines and manage routing'),
            self::FolioDiscount => __('Apply and remove folio discounts'),
            self::FolioDiscountOpen => __('Apply discounts with an entered amount'),
            self::FolioClose => __('Close folios'),
            self::FolioReopen => __('Reopen closed folios'),
            self::FolioPrintVoucher => __('Print folio vouchers and invoices'),
            self::PaymentRecord => __('Record payments and download receipts'),
            self::PaymentRefund => __('Refund payments'),
            self::CompanyView => __('View companies'),
            self::CompanyManage => __('Manage companies'),
            self::CompanyCreditOverride => __('Pass a company credit limit check'),
            self::CityLedgerInvoice => __('Settle folios to City ledger and void AR invoices'),
            self::CityLedgerCreditNote => __('Issue and void AR credit notes'),
            self::CityLedgerPayment => __('Record and void AR payments'),
            self::CityLedgerAllocate => __('Allocate AR payments and reverse allocations'),
            self::RegistrationCardManageTerms => __('Manage registration terms'),
            self::RegistrationCardSign => __('Prepare and sign registration cards'),
            self::RegistrationCardSendLink => __('Send registration card links'),
            self::RegistrationCardVoid => __('Void registration cards'),
            self::FrontDeskCheckIn => __('Check in units'),
            self::FrontDeskCheckOut => __('Check out units'),
            self::FrontDeskCheckInWithoutCard => __('Check in without a registration card'),
            self::FrontDeskWaiveFees => __('Do not charge cancellation and no-show fees'),
            self::FrontDeskUndoCheckIn => __('Undo a check-in made today'),
            self::FrontDeskUndoCheckOut => __('Undo a check-out made today'),
            self::NightAuditRun => __('Run the night audit'),
            self::NightAuditForce => __('Force close the night audit'),
            self::NightAuditReceiveReport => __('Receive the night audit report'),
            self::ReportsView => __('View and export reports'),
            self::HousekeepingView => __('View the housekeeping board'),
            self::HousekeepingUpdate => __('Update room status, assignments and flags'),
            self::HousekeepingInspect => __('Mark rooms as inspected'),
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
            self::ReservationsView, self::ReservationsManage, self::ReservationsChangePrice => __('Reservations'),
            self::FolioPostCharge, self::FolioReverse, self::FolioTransfer, self::FolioDiscount, self::FolioDiscountOpen, self::FolioClose, self::FolioReopen, self::FolioPrintVoucher => __('Folio'),
            self::PaymentRecord, self::PaymentRefund => __('Payments'),
            self::CompanyView, self::CompanyManage, self::CompanyCreditOverride, self::CityLedgerInvoice, self::CityLedgerCreditNote, self::CityLedgerPayment, self::CityLedgerAllocate => __('City ledger'),
            self::RegistrationCardManageTerms, self::RegistrationCardSign, self::RegistrationCardSendLink, self::RegistrationCardVoid => __('Registration card'),
            self::FrontDeskCheckIn, self::FrontDeskCheckOut, self::FrontDeskCheckInWithoutCard, self::FrontDeskWaiveFees, self::FrontDeskUndoCheckIn, self::FrontDeskUndoCheckOut => __('Front desk'),
            self::NightAuditRun, self::NightAuditForce, self::NightAuditReceiveReport => __('Night audit'),
            self::ReportsView => __('Reports'),
            self::HousekeepingView, self::HousekeepingUpdate, self::HousekeepingInspect => __('Housekeeping'),
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
