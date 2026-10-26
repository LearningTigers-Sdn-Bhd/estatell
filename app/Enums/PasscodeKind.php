<?php

namespace App\Enums;

enum PasscodeKind: string
{
    case Generated = 'generated';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Generated => __('Generated'),
            self::Custom => __('Custom'),
        };
    }

    /**
     * Only a custom PIN is sent to the lock, so only it needs a gateway.
     */
    public function needsGateway(): bool
    {
        return $this === self::Custom;
    }
}
