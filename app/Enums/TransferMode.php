<?php

namespace App\Enums;

/**
 * How a land transfer is charged: per person on a shared van, once for a private vehicle, or included in the package.
 */
enum TransferMode: string
{
    case Shared = 'shared';
    case Private = 'private';
    case Included = 'included';

    public function label(): string
    {
        return match ($this) {
            self::Shared => __('Shared'),
            self::Private => __('Private vehicle'),
            self::Included => __('In package'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
