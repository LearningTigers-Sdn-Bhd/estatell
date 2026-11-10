<?php

namespace App\Enums;

/**
 * Who runs a door lock. TTLock locks are driven from the cloud (PINs, cards, remote unlock). eLock locks only open with a key card
 * that Estatell Keyport, on the front-desk PC, writes from a job.
 */
enum LockProvider: string
{
    case TTLock = 'ttlock';
    case ELock = 'elock';

    public function label(): string
    {
        return match ($this) {
            self::TTLock => 'TTLock',
            self::ELock => 'eLock',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $provider): array => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
