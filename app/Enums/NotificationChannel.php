<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Mail = 'mail';
    case Database = 'database';

    public function label(): string
    {
        return match ($this) {
            self::Mail => __('Email'),
            self::Database => __('In-app'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $channel): array => ['value' => $channel->value, 'label' => $channel->label()],
            self::cases(),
        );
    }
}
