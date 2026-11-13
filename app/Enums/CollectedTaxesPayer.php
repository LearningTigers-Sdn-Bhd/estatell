<?php

namespace App\Enums;

/**
 * Who pays the taxes flagged **Collect at check-in** on one booking with a company, over the company's
 * `pays_collected_taxes`. A null `reservations.collected_taxes_payer` follows the company. See
 * docs/13-folio/03-routing.md#collected-taxes.
 */
enum CollectedTaxesPayer: string
{
    case Company = 'company';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Company pays'),
            self::Guest => __('Guest pays'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $payer): array => ['value' => $payer->value, 'label' => $payer->label()],
            self::cases(),
        );
    }
}
