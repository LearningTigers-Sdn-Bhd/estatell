<?php

namespace App\Enums;

enum MediaDisk: string
{
    case Local = 'local';
    case R2 = 'r2';

    public function label(): string
    {
        return match ($this) {
            self::Local => __('This server'),
            self::R2 => __('Cloudflare R2'),
        };
    }

    /**
     * The filesystem disk name in config/filesystems.php.
     */
    public function diskName(): string
    {
        return $this->value;
    }

    /**
     * Whether the disk has everything it needs to store files.
     */
    public function isConfigured(): bool
    {
        return match ($this) {
            self::Local => true,
            self::R2 => filled(config('filesystems.disks.r2.key'))
                && filled(config('filesystems.disks.r2.secret'))
                && filled(config('filesystems.disks.r2.bucket'))
                && filled(config('filesystems.disks.r2.endpoint')),
        };
    }

    /**
     * @return list<self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(self::cases(), fn (self $disk): bool => $disk->isConfigured()));
    }

    /**
     * @return list<array{value: string, label: string, disabled: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $disk): array => ['value' => $disk->value, 'label' => $disk->label(), 'disabled' => ! $disk->isConfigured()],
            self::cases(),
        );
    }
}
