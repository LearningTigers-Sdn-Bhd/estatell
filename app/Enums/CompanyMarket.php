<?php

namespace App\Enums;

/**
 * The market a company books in: local or international. Staff set it on the company; it is never derived from the
 * billing country. It decides which rate plans the company is offered. See docs/08-rates/01-rate-plans.md#market.
 */
enum CompanyMarket: string
{
    case Local = 'local';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::Local => __('Local'),
            self::International => __('International'),
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
}
