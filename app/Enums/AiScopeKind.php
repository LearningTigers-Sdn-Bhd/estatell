<?php

namespace App\Enums;

/**
 * Whether an AI scope is a place where the assistant answers or a capability (a group of tools) it may use.
 */
enum AiScopeKind: string
{
    case Place = 'place';
    case Capability = 'capability';

    public function label(): string
    {
        return match ($this) {
            self::Place => __('Places'),
            self::Capability => __('Capabilities'),
        };
    }
}
