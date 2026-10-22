<?php

namespace App\Enums;

enum SystemTransactionCode: string
{
    case RoomRevenue = 'room_revenue';
    case NoShowRevenue = 'no_show_revenue';
    case CancellationRevenue = 'cancellation_revenue';
    case LateCheckoutRevenue = 'late_checkout_revenue';
    case EarlyDepartureRevenue = 'early_departure_revenue';
    case DayUseRevenue = 'day_use_revenue';
    case FoodAndBeverageRevenue = 'food_and_beverage_revenue';
    case LaundryRevenue = 'laundry_revenue';
    case MinibarRevenue = 'minibar_revenue';
    case FeeRevenue = 'fee_revenue';
    case DamageRevenue = 'damage_revenue';
    case SstTax = 'sst_tax';
    case TourismTax = 'tourism_tax';
    case CashPayment = 'cash_payment';
    case CardPayment = 'card_payment';
    case BankTransferPayment = 'bank_transfer_payment';
    case EWalletPayment = 'ewallet_payment';
    case OtaCollectedPayment = 'ota_collected_payment';
    case DirectBillPayment = 'direct_bill_payment';
    case SecurityDeposit = 'security_deposit';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Discount = 'discount';
    case OpeningBalance = 'opening_balance';
    case OldSystemPayment = 'old_system_payment';

    public function code(): string
    {
        return match ($this) {
            self::RoomRevenue => 'ROOM',
            self::NoShowRevenue => 'NO_SHOW',
            self::CancellationRevenue => 'CANCEL',
            self::LateCheckoutRevenue => 'LATE_CHECKOUT',
            self::EarlyDepartureRevenue => 'EARLY_DEPARTURE',
            self::DayUseRevenue => 'DAY_USE',
            self::FoodAndBeverageRevenue => 'FNB',
            self::LaundryRevenue => 'LAUNDRY',
            self::MinibarRevenue => 'MINIBAR',
            self::FeeRevenue => 'FEE',
            self::DamageRevenue => 'DAMAGE',
            self::SstTax => 'SST',
            self::TourismTax => 'TOURISM_TAX',
            self::CashPayment => 'CASH',
            self::CardPayment => 'CARD',
            self::BankTransferPayment => 'BANK_TRANSFER',
            self::EWalletPayment => 'E_WALLET',
            self::OtaCollectedPayment => 'OTA_COLLECTED',
            self::DirectBillPayment => 'DIRECT_BILL',
            self::SecurityDeposit => 'SECURITY_DEPOSIT',
            self::Refund => 'REFUND',
            self::Adjustment => 'ADJUSTMENT',
            self::Discount => 'DISCOUNT',
            self::OpeningBalance => 'OPENING_BAL',
            self::OldSystemPayment => 'OLD_SYSTEM',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RoomRevenue => __('Room revenue'),
            self::NoShowRevenue => __('No-show revenue'),
            self::CancellationRevenue => __('Cancellation revenue'),
            self::LateCheckoutRevenue => __('Late checkout revenue'),
            self::EarlyDepartureRevenue => __('Early departure revenue'),
            self::DayUseRevenue => __('Day use'),
            self::FoodAndBeverageRevenue => __('Food and beverage'),
            self::LaundryRevenue => __('Laundry'),
            self::MinibarRevenue => __('Minibar'),
            self::FeeRevenue => __('General fee'),
            self::DamageRevenue => __('Damage'),
            self::SstTax => __('SST'),
            self::TourismTax => __('Tourism tax'),
            self::CashPayment => __('Cash'),
            self::CardPayment => __('Card'),
            self::BankTransferPayment => __('Bank transfer'),
            self::EWalletPayment => __('E-wallet'),
            self::OtaCollectedPayment => __('OTA collected'),
            self::DirectBillPayment => __('City ledger'),
            self::SecurityDeposit => __('Security deposit'),
            self::Refund => __('Refund'),
            self::Adjustment => __('Adjustment'),
            self::Discount => __('Discount'),
            self::OpeningBalance => __('Opening balance'),
            self::OldSystemPayment => __('Old system'),
        };
    }

    public function kind(): TransactionKind
    {
        return match ($this) {
            self::RoomRevenue,
            self::NoShowRevenue,
            self::CancellationRevenue,
            self::LateCheckoutRevenue,
            self::EarlyDepartureRevenue,
            self::DayUseRevenue,
            self::FoodAndBeverageRevenue,
            self::LaundryRevenue,
            self::MinibarRevenue,
            self::FeeRevenue,
            self::DamageRevenue => TransactionKind::Charge,
            self::SstTax, self::TourismTax => TransactionKind::Tax,
            self::CashPayment,
            self::CardPayment,
            self::BankTransferPayment,
            self::EWalletPayment,
            self::OtaCollectedPayment,
            self::DirectBillPayment,
            self::OldSystemPayment,
            self::SecurityDeposit,
            self::Refund => TransactionKind::Payment,
            self::Adjustment, self::Discount, self::OpeningBalance => TransactionKind::Adjustment,
        };
    }

    public function category(): TransactionCategory
    {
        return match ($this) {
            self::RoomRevenue,
            self::NoShowRevenue,
            self::CancellationRevenue,
            self::LateCheckoutRevenue,
            self::EarlyDepartureRevenue,
            self::DayUseRevenue => TransactionCategory::Room,
            self::FoodAndBeverageRevenue => TransactionCategory::FoodAndBeverage,
            self::LaundryRevenue => TransactionCategory::Laundry,
            self::MinibarRevenue => TransactionCategory::Minibar,
            self::FeeRevenue => TransactionCategory::Fee,
            self::DamageRevenue => TransactionCategory::Other,
            self::SstTax, self::TourismTax => TransactionCategory::Tax,
            self::CashPayment => TransactionCategory::Cash,
            self::CardPayment => TransactionCategory::Card,
            self::BankTransferPayment => TransactionCategory::BankTransfer,
            self::EWalletPayment => TransactionCategory::EWallet,
            self::OtaCollectedPayment => TransactionCategory::OtaCollected,
            self::DirectBillPayment => TransactionCategory::DirectBill,
            self::SecurityDeposit => TransactionCategory::Deposit,
            self::Refund => TransactionCategory::Refund,
            self::Adjustment => TransactionCategory::Adjustment,
            self::Discount => TransactionCategory::Discount,
            self::OpeningBalance => TransactionCategory::OpeningBalance,
            self::OldSystemPayment => TransactionCategory::OldSystem,
        };
    }

    /**
     * The room revenue codes. They always share one set of taxes.
     *
     * @return list<self>
     */
    public static function roomRevenueCases(): array
    {
        return [
            self::RoomRevenue,
            self::NoShowRevenue,
            self::CancellationRevenue,
            self::LateCheckoutRevenue,
            self::EarlyDepartureRevenue,
        ];
    }

    /**
     * The room revenue codes that carry a policy. Room nights have none.
     *
     * @return list<self>
     */
    public static function policyCases(): array
    {
        return [
            self::NoShowRevenue,
            self::CancellationRevenue,
            self::EarlyDepartureRevenue,
            self::LateCheckoutRevenue,
        ];
    }

    /** @return list<string> */
    public static function roomRevenueKeys(): array
    {
        return array_map(fn (self $code): string => $code->value, self::roomRevenueCases());
    }
}
