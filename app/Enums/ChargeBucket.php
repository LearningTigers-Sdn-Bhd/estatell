<?php

namespace App\Enums;

/**
 * The revenue bucket of a ledger line, shared by Daily report › Revenue and Daily report › Charges so the two tabs
 * cannot drift. A charge goes in Accommodation (category Room, without day use), Day use (the day use system code),
 * Fees (category Fee) or Extras (every other charge); an adjustment goes in Adjustments. A tax line has no bucket.
 * The value is the `?bucket` filter of the Charges tab. See docs/22-reports/02-guest-list-and-daily-report.md#charges.
 */
enum ChargeBucket: string
{
    case Accommodation = 'accommodation';
    case DayUse = 'day-use';
    case Fees = 'fees';
    case Extras = 'extras';
    case Adjustments = 'adjustments';

    public function label(): string
    {
        return match ($this) {
            self::Accommodation => __('Accommodation'),
            self::DayUse => __('Day use'),
            self::Fees => __('Fees'),
            self::Extras => __('Extras'),
            self::Adjustments => __('Adjustments'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $bucket): array => ['value' => $bucket->value, 'label' => $bucket->label()],
            self::cases(),
        );
    }

    /**
     * The bucket of a line, or null for a line that has none (a tax or a payment).
     *
     * @param  string  $category  The `TransactionCategory` value of the line's transaction code.
     * @param  string|null  $systemKey  The system key of the line's transaction code.
     */
    public static function forLine(TransactionKind $kind, string $category, ?string $systemKey): ?self
    {
        return match (true) {
            $kind === TransactionKind::Adjustment => self::Adjustments,
            $kind !== TransactionKind::Charge => null,
            $systemKey === SystemTransactionCode::DayUseRevenue->value => self::DayUse,
            $category === TransactionCategory::Room->value => self::Accommodation,
            $category === TransactionCategory::Fee->value => self::Fees,
            default => self::Extras,
        };
    }
}
