<?php

namespace App\Enums;

/**
 * Whether the estatell team has connected an OTA credential in the channel manager. Pending until the team marks it
 * Processed. See docs/17-onboarding/02-wizard-steps.md#channel-manager-table.
 */
enum OtaCredentialStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Processed => __('Processed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Processed => Tone::Success,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
