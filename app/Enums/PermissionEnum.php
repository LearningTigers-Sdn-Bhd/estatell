<?php

namespace App\Enums;

/**
 * Every staff permission. Each module adds its own cases and maps them in DefaultRoleEnum.
 * SyncPermissions turns new cases into permission rows. See docs/03-staff/02-roles-and-permissions.md.
 */
enum PermissionEnum: string
{
    case FrontDeskView = 'front-desk.view';
    case FrontDeskCheckIn = 'front-desk.check-in';
    case FrontDeskCheckOut = 'front-desk.check-out';
    case FrontDeskCheckInWithoutCard = 'front-desk.check-in-without-card';
    case FrontDeskWaiveFees = 'front-desk.waive-fees';
    case FrontDeskUndoCheckIn = 'front-desk.undo-check-in';
    case FrontDeskUndoCheckOut = 'front-desk.undo-check-out';
    case RegistrationCardSign = 'registration-card.sign';
    case RegistrationCardSendLink = 'registration-card.send-link';
    case RegistrationCardVoid = 'registration-card.void';
    case ReservationsView = 'reservations.view';
    case ReservationsManage = 'reservations.manage';
    case ReservationsChangePrice = 'reservations.change-price';
    case EventsView = 'events.view';
    case EventsManage = 'events.manage';
    case FacilitiesBook = 'facilities.book';
    case RatesView = 'rates.view';
    case RatesManage = 'rates.manage';
    case RatesOverrideRestrictions = 'rates.override-restrictions';
    case GuestsView = 'guests.view';
    case GuestsManage = 'guests.manage';
    case GuestsBlacklist = 'guests.blacklist';
    case GuestsViewDocuments = 'guests.view-documents';
    case GuestsMerge = 'guests.merge';
    case GuestsAnonymize = 'guests.anonymize';
    case GuestRequestsView = 'guest-requests.view';
    case GuestRequestsManage = 'guest-requests.manage';
    case GuestRequestsResolve = 'guest-requests.resolve';
    case AiChatsClose = 'ai-chats.close';
    case FolioPostCharge = 'folio.post-charge';
    case FolioReverse = 'folio.reverse';
    case FolioTransfer = 'folio.transfer';
    case FolioDiscount = 'folio.discount';
    case FolioDiscountOpen = 'folio.discount-open';
    case FolioClose = 'folio.close';
    case FolioReopen = 'folio.reopen';
    case FolioPrintVoucher = 'folio.print-voucher';
    case FolioManual = 'folio.manual';
    case FolioViewManualEntries = 'folio.view-manual-entries';
    case FolioWaiveTax = 'folio.waive-tax';
    case PaymentRecord = 'payment.record';
    case PaymentRefund = 'payment.refund';
    case PaymentRefundOtherMethod = 'payment.refund-other-method';
    case RoomsView = 'rooms.view';
    case RoomsManage = 'rooms.manage';
    case RoomsBlock = 'rooms.block';
    case HousekeepingView = 'housekeeping.view';
    case HousekeepingUpdate = 'housekeeping.update';
    case HousekeepingInspect = 'housekeeping.inspect';
    case HousekeepingEditTimes = 'housekeeping.edit-times';
    case NightAuditRun = 'night-audit.run';
    case NightAuditForce = 'night-audit.force';
    case NightAuditReceiveReport = 'night-audit.receive-report';
    case ReportsView = 'reports.view';
    case CompanyView = 'company.view';
    case CompanyManage = 'company.manage';
    case CompanyCreditOverride = 'company.credit-override';
    case CityLedgerInvoice = 'city-ledger.invoice';
    case CityLedgerCreditNote = 'city-ledger.credit-note';
    case CityLedgerPayment = 'city-ledger.payment';
    case CityLedgerAllocate = 'city-ledger.allocate';
    case EInvoiceView = 'einvoice.view';
    case EInvoiceManage = 'einvoice.manage';
    case PayoutsView = 'payouts.view';
    case PosView = 'pos.view';
    case PosManage = 'pos.manage';
    case PosResolve = 'pos.resolve';
    case MealsRedeem = 'meals.redeem';
    case PosSell = 'pos.sell';
    case PosShift = 'pos.shift';
    case PosVoid = 'pos.void';
    case PosDiscount = 'pos.discount';
    case PosRefund = 'pos.refund';
    case PosKds = 'pos.kds';
    case PosMenu = 'pos.menu';
    case PosInventory = 'pos.inventory';
    case PosHardware = 'pos.hardware';
    case PosReports = 'pos.reports';
    case SettingsManage = 'settings.manage';
    case NotificationManage = 'notification.manage';
    case RegistrationCardManageTerms = 'registration-card.manage-terms';
    case BookingWebsiteManage = 'booking-website.manage';
    case BookingWebsitePublish = 'booking-website.publish';
    case BookingWebsiteApi = 'booking-website.api';
    case BookingWebsitePaymentMode = 'booking-website.payment-mode';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesManage = 'roles.manage';

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
            self::GuestsAnonymize => __('Archive guests'),
            self::ReservationsView => __('View reservations'),
            self::ReservationsManage => __('Manage reservations'),
            self::ReservationsChangePrice => __('Change the price of booked nights'),
            self::EventsView => __('View events'),
            self::EventsManage => __('Create and edit events'),
            self::FacilitiesBook => __('Book facilities'),
            self::FolioPostCharge => __('Post charges to folios'),
            self::FolioReverse => __('Reverse folio lines'),
            self::FolioTransfer => __('Transfer folio lines and manage routing'),
            self::FolioDiscount => __('Apply and remove folio discounts'),
            self::FolioDiscountOpen => __('Apply discounts with an entered amount'),
            self::FolioClose => __('Close folios'),
            self::FolioReopen => __('Reopen closed folios'),
            self::FolioPrintVoucher => __('Print folio vouchers and invoices'),
            self::FolioManual => __('Use the Manual Folio Editor'),
            self::FolioViewManualEntries => __('See which folio lines were entered manually'),
            self::FolioWaiveTax => __('Waive taxes paid elsewhere'),
            self::PaymentRecord => __('Record payments and download receipts'),
            self::PaymentRefund => __('Refund payments'),
            self::PaymentRefundOtherMethod => __('Refund to a method other than the payment\'s own'),
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
            self::FrontDeskView => __('View the Timeline View and Room View'),
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
            self::HousekeepingEditTimes => __('Correct housekeeping IN and OUT times'),
            self::BookingWebsiteManage => __('Edit the booking website settings'),
            self::BookingWebsitePublish => __('Publish or unpublish the booking website'),
            self::BookingWebsiteApi => __('Manage the booking API token and return addresses'),
            self::BookingWebsitePaymentMode => __('Switch booking website payments between Test and Live'),
            self::GuestRequestsView => __('View guest requests'),
            self::GuestRequestsManage => __('Record, assign, cancel and reopen guest requests'),
            self::GuestRequestsResolve => __('Start and resolve guest requests'),
            self::AiChatsClose => __('Close AI chats so the next message starts a new chat'),
            self::EInvoiceView => __('View e-invoices and submissions'),
            self::EInvoiceManage => __('Submit, cancel, and configure e-invoices'),
            self::PayoutsView => __('View payouts and commission from Estatell'),
            self::PosView => __('View POS postings and receipt details'),
            self::PosManage => __('Manage POS outlets, connections and API access'),
            self::PosResolve => __('Attach or ignore POS postings that need review'),
            self::MealsRedeem => __('Check off daily restaurant meal entitlements'),
            self::PosSell => __('Open the POS terminal, take orders and settle payments'),
            self::PosShift => __('Open and close register shifts and record cash movements'),
            self::PosVoid => __('Void fired POS lines and orders, reprint pre-bills and open the drawer'),
            self::PosDiscount => __('Apply restricted POS discounts, comps and price overrides'),
            self::PosRefund => __('Refund settled POS orders and change the payment method'),
            self::PosKds => __('Use the kitchen display and update kitchen line statuses'),
            self::PosMenu => __('Manage the POS menu, modifiers, floors and availability'),
            self::PosInventory => __('Manage POS ingredients, recipes, purchases and stocktakes'),
            self::PosHardware => __('Pair POS hardware and configure printers and stations'),
            self::PosReports => __('View POS sales, shift Z-reports and cash audit'),
        };
    }

    /**
     * The feature group the permission sits under on the roles page. Cases are listed in group order.
     */
    public function group(): string
    {
        return match ($this) {
            self::FrontDeskView, self::FrontDeskCheckIn, self::FrontDeskCheckOut, self::FrontDeskCheckInWithoutCard, self::FrontDeskWaiveFees, self::FrontDeskUndoCheckIn, self::FrontDeskUndoCheckOut, self::RegistrationCardSign, self::RegistrationCardSendLink, self::RegistrationCardVoid => __('Front desk'),
            self::ReservationsView, self::ReservationsManage, self::ReservationsChangePrice, self::EventsView, self::EventsManage, self::FacilitiesBook, self::RatesView, self::RatesManage, self::RatesOverrideRestrictions => __('Reservations'),
            self::GuestsView, self::GuestsManage, self::GuestsBlacklist, self::GuestsViewDocuments, self::GuestsMerge, self::GuestsAnonymize, self::GuestRequestsView, self::GuestRequestsManage, self::GuestRequestsResolve, self::AiChatsClose => __('Guests'),
            self::FolioPostCharge, self::FolioReverse, self::FolioTransfer, self::FolioDiscount, self::FolioDiscountOpen, self::FolioClose, self::FolioReopen, self::FolioPrintVoucher, self::FolioManual, self::FolioViewManualEntries, self::FolioWaiveTax, self::PaymentRecord, self::PaymentRefund, self::PaymentRefundOtherMethod => __('Folio and payments'),
            self::RoomsView, self::RoomsManage, self::RoomsBlock, self::HousekeepingView, self::HousekeepingUpdate, self::HousekeepingInspect, self::HousekeepingEditTimes, self::NightAuditRun, self::NightAuditForce, self::NightAuditReceiveReport, self::ReportsView => __('Operations'),
            self::CompanyView, self::CompanyManage, self::CompanyCreditOverride, self::CityLedgerInvoice, self::CityLedgerCreditNote, self::CityLedgerPayment, self::CityLedgerAllocate, self::EInvoiceView, self::EInvoiceManage, self::PayoutsView => __('Finance'),
            self::PosView, self::PosManage, self::PosResolve, self::MealsRedeem, self::PosSell, self::PosShift, self::PosVoid, self::PosDiscount, self::PosRefund, self::PosKds, self::PosMenu, self::PosInventory, self::PosHardware, self::PosReports => __('POS and restaurant'),
            self::SettingsManage, self::NotificationManage, self::RegistrationCardManageTerms, self::BookingWebsiteManage, self::BookingWebsitePublish, self::BookingWebsiteApi, self::BookingWebsitePaymentMode, self::StaffView, self::StaffManage, self::RolesManage => __('Setup'),
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
