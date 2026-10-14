<?php

namespace App\Enums;

/**
 * Why a support user views a property as its staff. Shown as chips on the view-as page. The case order is the chip
 * order, and the first case is the fallback for an unknown `?reason=`.
 */
enum ImpersonationReason: string
{
    case OnboardingHelp = 'onboarding_help';
    case Support = 'support';
    case SetupCheck = 'setup_check';

    public function label(): string
    {
        return match ($this) {
            self::OnboardingHelp => __('Onboarding help'),
            self::Support => __('Support'),
            self::SetupCheck => __('Setup check'),
        };
    }

    /**
     * The chip a link selects: the given value, the first chip when the value is unknown, or Support when none is given.
     */
    public static function fromLink(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Support;
        }

        return self::tryFrom($value) ?? self::cases()[0];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
