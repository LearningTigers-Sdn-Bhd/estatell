<?php

namespace App\Enums;

enum FieldVisibility: string
{
    case Hidden = 'hidden';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => __('Hidden'),
            self::Optional => __('Optional'),
            self::Required => __('Required'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Hidden => Tone::Neutral,
            self::Optional => Tone::Info,
            self::Required => Tone::Success,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $visibility): array => ['value' => $visibility->value, 'label' => $visibility->label()],
            self::cases(),
        );
    }
}
