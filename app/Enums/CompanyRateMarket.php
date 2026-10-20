<?php

namespace App\Enums;

/**
 * The market a rate plan is offered to: every company, local companies only or international companies only. A
 * company with no market sees only plans open to every market. See docs/08-rates/01-rate-plans.md#market.
 */
enum CompanyRateMarket: string
{
    case All = 'all';
    case Local = 'local';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::All => __('Local and international'),
            self::Local => __('Local companies only'),
            self::International => __('International companies only'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $market): array => ['value' => $market->value, 'label' => $market->label()],
            self::cases(),
        );
    }

    /**
     * Whether a plan with this market is offered to a company in the given market (null when the company has none).
     */
    public function offersTo(?CompanyMarket $companyMarket): bool
    {
        return $this === self::All || ($companyMarket !== null && $this->value === $companyMarket->value);
    }

    /**
     * The plan market values a company in the given market can be offered, for queries.
     *
     * @return list<string>
     */
    public static function valuesOfferedTo(?CompanyMarket $companyMarket): array
    {
        return $companyMarket === null ? [self::All->value] : [self::All->value, $companyMarket->value];
    }
}
