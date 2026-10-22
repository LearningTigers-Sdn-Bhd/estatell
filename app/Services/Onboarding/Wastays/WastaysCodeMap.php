<?php

namespace App\Services\Onboarding\Wastays;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * What each wastays transaction code means in estatell, and the small conversions the setup import needs.
 * See docs/migration-plan/grand-eastern-hotel.md "Setup import".
 */
class WastaysCodeMap
{
    /** The tax rate wastays prices include. estatell stores the net price with SST on top. */
    public const string SST_DIVISOR = '1.08';

    /** @var array<string, SystemTransactionCode> */
    private const array PAYMENTS = [
        'CASH' => SystemTransactionCode::CashPayment,
        'CASH_PRE' => SystemTransactionCode::CashPayment,
        'CASH_ADV' => SystemTransactionCode::CashPayment,
        'CARD' => SystemTransactionCode::CardPayment,
        'CARD_ADV' => SystemTransactionCode::CardPayment,
        'GATEWAY' => SystemTransactionCode::CardPayment,
        'BANK' => SystemTransactionCode::BankTransferPayment,
        'OTA' => SystemTransactionCode::OtaCollectedPayment,
    ];

    /** @var array<string, SystemTransactionCode> */
    private const array TAXES = [
        'TAX_SST' => SystemTransactionCode::SstTax,
        'TAX_TTX' => SystemTransactionCode::TourismTax,
        'TAX' => SystemTransactionCode::TourismTax,
    ];

    /** @var array<string, SystemTransactionCode> */
    private const array SYSTEM_CODES = [
        'ROOM' => SystemTransactionCode::RoomRevenue,
        'NO_SHOW' => SystemTransactionCode::NoShowRevenue,
        'CANCEL' => SystemTransactionCode::CancellationRevenue,
        'LATE_CO' => SystemTransactionCode::LateCheckoutRevenue,
        'EARLY_DEP' => SystemTransactionCode::EarlyDepartureRevenue,
        'SECDEP' => SystemTransactionCode::SecurityDeposit,
        'REFUND' => SystemTransactionCode::Refund,
        'ADJUSTMENT' => SystemTransactionCode::Adjustment,
        'REBATE' => SystemTransactionCode::Discount,
    ];

    /** @var array<string, SystemTransactionCode> Charge codes that land on a starter or built-in charge item. */
    private const array STARTER_CHARGES = [
        'FNB' => SystemTransactionCode::FoodAndBeverageRevenue,
        'DAMAGE' => SystemTransactionCode::DamageRevenue,
    ];

    /** @var array<string, array{name: string, code: string, category: TransactionCategory}> Charge codes that become a new charge item. */
    private const array NEW_CHARGES = [
        'CLEANING' => ['name' => 'Cleaning Fee', 'code' => 'CLEANING', 'category' => TransactionCategory::Fee],
        'MISC' => ['name' => 'Miscellaneous Revenue', 'code' => 'MISC', 'category' => TransactionCategory::Other],
        'PARK' => ['name' => 'Parking', 'code' => 'PARKING', 'category' => TransactionCategory::Fee],
        'BED' => ['name' => 'Extra Bed', 'code' => 'EXTRA_BED', 'category' => TransactionCategory::Fee],
    ];

    public function payment(string $code): ?SystemTransactionCode
    {
        return self::PAYMENTS[$code] ?? null;
    }

    public function tax(string $code): ?SystemTransactionCode
    {
        return self::TAXES[$code] ?? null;
    }

    public function systemCode(string $code): ?SystemTransactionCode
    {
        return self::SYSTEM_CODES[$code] ?? null;
    }

    public function starterCharge(string $code): ?SystemTransactionCode
    {
        return self::STARTER_CHARGES[$code] ?? null;
    }

    /** @return array{name: string, code: string, category: TransactionCategory}|null */
    public function newCharge(string $code): ?array
    {
        return self::NEW_CHARGES[$code] ?? null;
    }

    /**
     * The estatell rate plan a wastays rate plan name belongs to: standard, walk-in or corporate. Null for any other name.
     */
    public function ratePlanKey(string $name): ?string
    {
        $name = mb_strtolower($name);

        return match (true) {
            str_contains($name, 'standard') => 'standard',
            str_contains($name, 'walk') => 'walk-in',
            str_contains($name, 'corporate') => 'corporate',
            default => null,
        };
    }

    public function titleCase(string $name): string
    {
        return Str::title(mb_strtolower(trim($name)));
    }

    /** A wastays price with SST included, as the net estatell price with two decimals. */
    public function netPrice(string $price): string
    {
        return (string) BigDecimal::of($price)->dividedBy(self::SST_DIVISOR, 2, RoundingMode::HalfUp);
    }

    public function isTrue(?string $value): bool
    {
        return in_array(mb_strtolower((string) $value), ['t', 'true', '1', 'yes'], true);
    }
}
