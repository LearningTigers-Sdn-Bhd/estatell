<?php

namespace App\Enums;

/**
 * Whether a folio bills a whole reservation (Master), one unit (Unit) or a separate payer such as a travel agent (ThirdParty), or bills an event for people who are not staying (Event). See docs/13-folio/01-folios-and-lines.md.
 */
enum FolioType: string
{
    case Master = 'master';
    case Unit = 'unit';
    case ThirdParty = 'third_party';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Master => __('Master'),
            self::Unit => __('Unit'),
            self::ThirdParty => __('Third party'),
            self::Event => __('Event'),
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
