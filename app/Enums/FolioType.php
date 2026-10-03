<?php

namespace App\Enums;

/**
 * Whether a folio bills a whole reservation (Master) or one unit (Unit). See docs/13-folio/01-folios-and-lines.md.
 */
enum FolioType: string
{
    case Master = 'master';
    case Unit = 'unit';

    public function label(): string
    {
        return match ($this) {
            self::Master => __('Master'),
            self::Unit => __('Unit'),
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
