<?php

namespace App\Enums;

enum PasscodeMode: string
{
    case Generated = 'generated';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Generated => __('Lock-generated'),
            self::Custom => __('Random PIN via gateway'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Generated => __('TTLock makes the PIN. Works without a gateway. The lock decides the length.'),
            self::Custom => __('estatell sets a random PIN of the length below on the lock. Needs a gateway, otherwise a lock-generated PIN is used.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label(), 'description' => $mode->description()], self::cases());
    }
}
