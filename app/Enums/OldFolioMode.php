<?php

namespace App\Enums;

/**
 * How a migrated stay carries its old folio over: one signed amount, or the old lines copied one by one. See
 * docs/12-reservations/12-past-and-migrated-stays.md#old-folio.
 */
enum OldFolioMode: string
{
    case OneAmount = 'one_amount';
    case CopyLineByLine = 'copy_line_by_line';

    public function label(): string
    {
        return match ($this) {
            self::OneAmount => __('One amount'),
            self::CopyLineByLine => __('Copy line by line'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
