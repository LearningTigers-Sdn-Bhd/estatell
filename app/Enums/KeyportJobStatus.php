<?php

namespace App\Enums;

/**
 * Where a Keyport job is. `Uncertain` means a card write may or may not have happened: staff check the card and
 * make a new job. It is never offered again.
 */
enum KeyportJobStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Uncertain = 'uncertain';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for the front desk PC'),
            self::Claimed => __('In progress'),
            self::Succeeded => __('Done'),
            self::Failed => __('Failed'),
            self::Uncertain => __('Check the card'),
            self::Cancelled => __('Cancelled'),
            self::Expired => __('Expired'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Claimed => 'info',
            self::Succeeded => 'success',
            self::Failed, self::Uncertain => 'danger',
            self::Cancelled, self::Expired => 'warning',
        };
    }

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Pending, self::Claimed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Claimed, self::Cancelled, self::Expired], true),
            self::Claimed => in_array($next, [self::Succeeded, self::Failed, self::Uncertain, self::Cancelled, self::Expired], true),
            default => false,
        };
    }

    /**
     * The statuses an agent may report in a result.
     *
     * @return list<string>
     */
    public static function reportable(): array
    {
        return [self::Succeeded->value, self::Failed->value, self::Uncertain->value, self::Cancelled->value, self::Expired->value];
    }
}
