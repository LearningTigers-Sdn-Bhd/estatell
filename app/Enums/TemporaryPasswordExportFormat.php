<?php

namespace App\Enums;

/**
 * How an owner hands out temp passwords: a copy message, or a downloaded file.
 */
enum TemporaryPasswordExportFormat: string
{
    case Message = 'message';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Message => __('Copy message'),
            self::Csv => __('CSV'),
            self::Xlsx => __('Excel (XLSX)'),
            self::Pdf => __('PDF slips'),
        };
    }

    public function isFile(): bool
    {
        return $this !== self::Message;
    }

    /**
     * The download formats, for the Export menu.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            fn (self $format): array => ['value' => $format->value, 'label' => $format->label()],
            array_filter(self::cases(), fn (self $format): bool => $format->isFile()),
        ));
    }
}
