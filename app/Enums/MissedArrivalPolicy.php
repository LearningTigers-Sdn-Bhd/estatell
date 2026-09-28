<?php

namespace App\Enums;

enum MissedArrivalPolicy: string
{
    case MarkNoShow = 'mark_no_show';
    case KeepAsLateArrival = 'keep_as_late_arrival';

    public function label(): string
    {
        return match ($this) {
            self::MarkNoShow => __('Mark no-show'),
            self::KeepAsLateArrival => __('Keep as late arrival'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
