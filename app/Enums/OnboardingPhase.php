<?php

namespace App\Enums;

/**
 * The phases of the onboarding wizard, in order. The phase bar shows one entry per case. Each step belongs to one
 * phase (see OnboardingStep::phase()). See docs/17-onboarding/02-wizard-steps.md.
 */
enum OnboardingPhase: string
{
    case Property = 'property';
    case Rooms = 'rooms';
    case Finance = 'finance';
    case Prices = 'prices';
    case CheckIn = 'check_in';
    case Team = 'team';
    case Channels = 'channels';
    case TestDrive = 'test_drive';

    public function label(): string
    {
        return match ($this) {
            self::Property => __('Property'),
            self::Rooms => __('Rooms'),
            self::Finance => __('Finance'),
            self::Prices => __('Prices'),
            self::CheckIn => __('Check-in'),
            self::Team => __('Team'),
            self::Channels => __('Channels'),
            self::TestDrive => __('Test drive'),
        };
    }

    /**
     * The steps of this phase, in wizard order.
     *
     * @return list<OnboardingStep>
     */
    public function steps(): array
    {
        return array_values(array_filter(
            OnboardingStep::cases(),
            fn (OnboardingStep $step): bool => $step->phase() === $this,
        ));
    }
}
