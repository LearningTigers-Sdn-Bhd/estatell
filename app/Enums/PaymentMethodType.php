<?php

namespace App\Enums;

enum PaymentMethodType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case EWallet = 'e_wallet';
    case OtaCollected = 'ota_collected';
    case DirectBill = 'direct_bill';
    case OldSystem = 'old_system';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::Card => __('Card'),
            self::BankTransfer => __('Bank transfer'),
            self::EWallet => __('E-wallet'),
            self::OtaCollected => __('OTA collected'),
            self::DirectBill => __('City ledger'),
            self::OldSystem => __('Old system'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }

    /** The transaction category of a payment code for this type. */
    public function category(): TransactionCategory
    {
        return match ($this) {
            self::Cash => TransactionCategory::Cash,
            self::Card => TransactionCategory::Card,
            self::BankTransfer => TransactionCategory::BankTransfer,
            self::EWallet => TransactionCategory::EWallet,
            self::OtaCollected => TransactionCategory::OtaCollected,
            self::DirectBill => TransactionCategory::DirectBill,
            self::OldSystem => TransactionCategory::OldSystem,
        };
    }

    /** Whether new methods of this type ask staff for a reference by default. */
    public function requiresReferenceByDefault(): bool
    {
        return match ($this) {
            self::Card, self::BankTransfer, self::EWallet => true,
            self::Cash, self::OtaCollected, self::DirectBill, self::OldSystem => false,
        };
    }

    /**
     * The types staff may add. OTA collected, City ledger and Old system stay system-only.
     *
     * @return list<self>
     */
    public static function addableCases(): array
    {
        return [self::Cash, self::Card, self::BankTransfer, self::EWallet];
    }

    /** @return list<array{value: string, label: string, requiresReference: bool}> */
    public static function addableOptions(): array
    {
        return array_map(
            fn (self $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'requiresReference' => $type->requiresReferenceByDefault(),
            ],
            self::addableCases(),
        );
    }
}
