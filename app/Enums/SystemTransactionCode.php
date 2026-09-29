<?php

namespace App\Enums;

enum SystemTransactionCode: string
{
    case RoomRevenue = 'room_revenue';
    case NoShowRevenue = 'no_show_revenue';
    case CancellationRevenue = 'cancellation_revenue';
    case LateCheckoutRevenue = 'late_checkout_revenue';
    case EarlyDepartureRevenue = 'early_departure_revenue';
    case FoodAndBeverageRevenue = 'food_and_beverage_revenue';
    case LaundryRevenue = 'laundry_revenue';
    case MinibarRevenue = 'minibar_revenue';
    case FeeRevenue = 'fee_revenue';
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

    public function code(): string
    {
        return match ($this) {
            self::RoomRevenue => 'ROOM',
            self::NoShowRevenue => 'NO_SHOW',
            self::CancellationRevenue => 'CANCEL',
            self::LateCheckoutRevenue => 'LATE_CHECKOUT',
            self::EarlyDepartureRevenue => 'EARLY_DEPARTURE',
            self::FoodAndBeverageRevenue => 'FNB',
            self::LaundryRevenue => 'LAUNDRY',
            self::MinibarRevenue => 'MINIBAR',
            self::FeeRevenue => 'FEE',
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
            self::FoodAndBeverageRevenue => __('Food and beverage'),
            self::LaundryRevenue => __('Laundry'),
            self::MinibarRevenue => __('Minibar'),
            self::FeeRevenue => __('General fee'),
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
            self::FoodAndBeverageRevenue,
            self::LaundryRevenue,
            self::MinibarRevenue,
            self::FeeRevenue => TransactionKind::Charge,
            self::SstTax, self::TourismTax => TransactionKind::Tax,
            self::CashPayment,
            self::CardPayment,
            self::BankTransferPayment,
            self::EWalletPayment,
            self::OtaCollectedPayment,
            self::DirectBillPayment,
            self::SecurityDeposit,
            self::Refund => TransactionKind::Payment,
            self::Adjustment, self::Discount => TransactionKind::Adjustment,
        };
    }

    public function category(): TransactionCategory
    {
        return match ($this) {
            self::RoomRevenue,
            self::NoShowRevenue,
            self::CancellationRevenue,
            self::LateCheckoutRevenue,
            self::EarlyDepartureRevenue => TransactionCategory::Room,
            self::FoodAndBeverageRevenue => TransactionCategory::FoodAndBeverage,
            self::LaundryRevenue => TransactionCategory::Laundry,
            self::MinibarRevenue => TransactionCategory::Minibar,
            self::FeeRevenue => TransactionCategory::Fee,
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
        };
    }
}
