<?php

namespace App\Enums;

/**
 * The kind of company. A label only: every type books and pays the same way. See docs/15-city-ledger/01-companies.md.
 */
enum CompanyType: string
{
    case Corporate = 'corporate';
    case TravelAgent = 'travel_agent';
    case Government = 'government';

    public function label(): string
    {
        return match ($this) {
            self::Corporate => __('Corporate'),
            self::TravelAgent => __('Travel agent'),
            self::Government => __('Government'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
