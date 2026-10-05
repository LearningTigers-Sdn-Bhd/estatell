<?php

namespace App\Enums;

/**
 * The names a terms text may use as `{{name}}`. They are filled when the terms are shown or signed.
 */
enum TermsPlaceholder: string
{
    case PropertyName = 'property_name';
    case CheckInTime = 'check_in_time';
    case CheckOutTime = 'check_out_time';
    case SecurityDeposit = 'security_deposit';
    case TourismTaxRate = 'tourism_tax_rate';

    public function label(): string
    {
        return match ($this) {
            self::PropertyName => __('Property name'),
            self::CheckInTime => __('Check-in time'),
            self::CheckOutTime => __('Check-out time'),
            self::SecurityDeposit => __('Security deposit'),
            self::TourismTaxRate => __('Tourism tax'),
        };
    }

    public function token(): string
    {
        return '{{'.$this->value.'}}';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $placeholder): array => ['value' => $placeholder->value, 'label' => $placeholder->label()],
            self::cases(),
        );
    }
}
