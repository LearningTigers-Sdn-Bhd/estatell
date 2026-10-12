<?php

namespace App\Enums;

enum BoatMode: string
{
    case Scheduled = 'scheduled';
    case Charter = 'charter';
    case Own = 'own';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled boat'),
            self::Charter => __('Charter'),
            self::Own => __('Own boat'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }

    /** Only a scheduled trip uses a departure. Charter and own boat trips type their own time. */
    public function needsDeparture(): bool
    {
        return $this === self::Scheduled;
    }
}
