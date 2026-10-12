<?php

namespace App\Enums;

enum TransactionCategory: string
{
    case Room = 'room';
    case FoodAndBeverage = 'food_and_beverage';
    case Laundry = 'laundry';
    case Minibar = 'minibar';
    case Transport = 'transport';
    case Fee = 'fee';
    case Tax = 'tax';
    case Discount = 'discount';
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';
    case OtaCollected = 'ota_collected';
    case DirectBill = 'direct_bill';
    case Deposit = 'deposit';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Room => __('Room'),
            self::FoodAndBeverage => __('Food and beverage'),
            self::Laundry => __('Laundry'),
            self::Minibar => __('Minibar'),
            self::Transport => __('Transport'),
            self::Fee => __('Fee'),
            self::Tax => __('Tax'),
            self::Discount => __('Discount'),
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::BankTransfer => __('Bank transfer'),
            self::EWallet => __('E-wallet'),
            self::OtaCollected => __('OTA collected'),
            self::DirectBill => __('City ledger'),
            self::Deposit => __('Deposit'),
            self::Refund => __('Refund'),
            self::Adjustment => __('Adjustment'),
            self::Other => __('Other'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::cases(),
        );
    }

    /**
     * The categories staff may choose for a charge item's own code.
     *
     * @return list<self>
     */
    public static function chargeItemCases(): array
    {
        return [self::FoodAndBeverage, self::Laundry, self::Minibar, self::Transport, self::Fee, self::Other];
    }

    /**
     * The categories a Manual routing rule may match: Room and the charge item categories. Tax, discount and payment
     * categories never route, because children follow their charge.
     *
     * @return list<self>
     */
    public static function routableCases(): array
    {
        return [self::Room, ...self::chargeItemCases()];
    }

    /** @return list<array{value: string, label: string}> */
    public static function chargeItemOptions(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'label' => $category->label()],
            self::chargeItemCases(),
        );
    }
}
