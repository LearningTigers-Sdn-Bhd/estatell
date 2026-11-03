<?php

namespace App\Actions\DoorLocks;

use App\Models\DoorCard;
use App\Models\DoorPasscode;

/**
 * The outcome of a door lock action. A refusal is an expected state (no gateway, bad PIN, past window), never an HTTP error.
 * A `warning` means the action worked but something stays true that staff must know, for example a PIN still valid on the lock.
 */
final class DoorLockResult
{
    public const REFUSAL_GATEWAY_REQUIRED = 'gateway_required';

    public const REFUSAL_NOT_CONNECTED = 'not_connected';

    public const REFUSAL_INVALID_CODE = 'invalid_code';

    public const REFUSAL_WINDOW_PASSED = 'window_passed';

    public const REFUSAL_TTLOCK = 'ttlock_error';

    public const REFUSAL_UNCHANGEABLE = 'not_changeable';

    public const REFUSAL_INVALID_CARD = 'invalid_card';

    public const REFUSAL_CARD_IN_USE = 'card_in_use';

    private function __construct(
        public readonly ?string $refusal,
        public readonly ?string $message,
        public readonly ?DoorPasscode $passcode,
        public readonly ?string $warning,
        public readonly ?DoorCard $card = null,
    ) {}

    public static function done(?DoorPasscode $passcode = null, ?string $warning = null, ?DoorCard $card = null): self
    {
        return new self(null, null, $passcode, $warning, $card);
    }

    public static function refused(string $refusal, ?string $message = null, ?DoorPasscode $passcode = null, ?DoorCard $card = null): self
    {
        return new self($refusal, $message ?? self::messageFor($refusal), $passcode, null, $card);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    private static function messageFor(string $refusal): string
    {
        return match ($refusal) {
            self::REFUSAL_GATEWAY_REQUIRED => __('This needs a gateway installed at the lock.'),
            self::REFUSAL_NOT_CONNECTED => __('Connect the TTLock account first.'),
            self::REFUSAL_INVALID_CODE => __('A PIN is 4 to 9 digits.'),
            self::REFUSAL_WINDOW_PASSED => __('The PIN time window has already ended.'),
            self::REFUSAL_UNCHANGEABLE => __('This PIN can no longer be changed.'),
            self::REFUSAL_INVALID_CARD => __('This card number is not valid.'),
            self::REFUSAL_CARD_IN_USE => __('This card is already registered for another stay on this lock.'),
            default => __('TTLock refused the request.'),
        };
    }
}
