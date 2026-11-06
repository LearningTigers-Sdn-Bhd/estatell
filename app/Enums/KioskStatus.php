<?php

namespace App\Enums;

enum KioskStatus: string
{
    case AwaitingPairing = 'awaiting_pairing';
    case Paired = 'paired';
    case CodeExpired = 'code_expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPairing => __('Waiting for pairing'),
            self::Paired => __('Paired'),
            self::CodeExpired => __('Code expired'),
            self::Revoked => __('Revoked'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::AwaitingPairing => 'info',
            self::Paired => 'success',
            self::CodeExpired => 'warning',
            self::Revoked => 'neutral',
        };
    }
}
