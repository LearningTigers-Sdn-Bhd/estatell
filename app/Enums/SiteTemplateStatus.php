<?php

namespace App\Enums;

/**
 * Draft templates are for admins only. Available ones can be picked. Retired ones leave the picker, and sites already using one keep working.
 */
enum SiteTemplateStatus: string
{
    case Draft = 'draft';
    case Available = 'available';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Available => __('Available'),
            self::Retired => __('Retired'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Warning,
            self::Available => Tone::Success,
            self::Retired => Tone::Neutral,
        };
    }

    /**
     * The button text for moving a template to this status.
     */
    public function actionLabel(): string
    {
        return match ($this) {
            self::Draft => __('Back to draft'),
            self::Available => __('Make available'),
            self::Retired => __('Retire'),
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Draft => $status === self::Available,
            self::Available => in_array($status, [self::Draft, self::Retired], true),
            self::Retired => $status === self::Available,
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
