<?php

namespace App\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => __('Male'),
            self::Female => __('Female'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $gender): array => ['value' => $gender->value, 'label' => $gender->label()],
            self::cases(),
        );
    }
}
