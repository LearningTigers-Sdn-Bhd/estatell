<?php

namespace App\Enums;

use App\Services\Onboarding\Checks\BasicRateCheck;
use App\Services\Onboarding\Checks\BasicsCheck;
use App\Services\Onboarding\Checks\ChannelManagerCheck;
use App\Services\Onboarding\Checks\ChargeItemsCheck;
use App\Services\Onboarding\Checks\CheckInEssentialsCheck;
use App\Services\Onboarding\Checks\CompaniesCheck;
use App\Services\Onboarding\Checks\DiscountsCheck;
use App\Services\Onboarding\Checks\PaymentMethodsCheck;
use App\Services\Onboarding\Checks\ReviewCheck;
use App\Services\Onboarding\Checks\RoomsCheck;
use App\Services\Onboarding\Checks\RoomTypesCheck;
use App\Services\Onboarding\Checks\StepCheck;
use App\Services\Onboarding\Checks\TaxesCheck;
use App\Services\Onboarding\Checks\TeamCheck;
use App\Services\Onboarding\Checks\TestDriveCheck;

/**
 * The fourteen steps of the onboarding wizard, in number order. Case order is the wizard order, and every step's
 * dependencies come earlier in it. The table is in docs/17-onboarding/02-wizard-steps.md.
 */
enum OnboardingStep: string
{
    case Basics = 'basics';
    case RoomTypes = 'room_types';
    case Rooms = 'rooms';
    case Taxes = 'taxes';
    case PaymentMethods = 'payment_methods';
    case Companies = 'companies';
    case BasicRate = 'basic_rate';
    case ChargeItems = 'charge_items';
    case Discounts = 'discounts';
    case CheckInEssentials = 'check_in_essentials';
    case Team = 'team';
    case ChannelManager = 'channel_manager';
    case TestDrive = 'test_drive';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Basics => __('Basics'),
            self::RoomTypes => __('Room types'),
            self::Rooms => __('Rooms'),
            self::Taxes => __('Taxes'),
            self::PaymentMethods => __('Payment methods'),
            self::Companies => __('Companies'),
            self::BasicRate => __('Basic rate'),
            self::ChargeItems => __('Charge items'),
            self::Discounts => __('Discounts'),
            self::CheckInEssentials => __('Check-in essentials'),
            self::Team => __('Team'),
            self::ChannelManager => __('Channel manager'),
            self::TestDrive => __('Test drive'),
            self::Review => __('Review'),
        };
    }

    /**
     * The 1-based number of the step in the wizard.
     */
    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function phase(): OnboardingPhase
    {
        return match ($this) {
            self::Basics => OnboardingPhase::Property,
            self::RoomTypes, self::Rooms => OnboardingPhase::Rooms,
            self::Taxes, self::PaymentMethods, self::Companies => OnboardingPhase::Finance,
            self::BasicRate, self::ChargeItems, self::Discounts => OnboardingPhase::Prices,
            self::CheckInEssentials => OnboardingPhase::CheckIn,
            self::Team => OnboardingPhase::Team,
            self::ChannelManager => OnboardingPhase::Channels,
            self::TestDrive => OnboardingPhase::TestDrive,
            self::Review => OnboardingPhase::Review,
        };
    }

    public function isRequired(): bool
    {
        return match ($this) {
            self::Basics, self::RoomTypes, self::Rooms, self::Taxes, self::PaymentMethods, self::BasicRate, self::CheckInEssentials => true,
            default => false,
        };
    }

    /**
     * Whether the owner may skip the step. Optional steps can be skipped. Taxes can also be answered with "We don't
     * charge tax", which counts as Done.
     */
    public function isSkippable(): bool
    {
        return ! $this->isRequired() || $this === self::Taxes;
    }

    /**
     * The steps that must be Done before this step opens (the "Locked until" column).
     *
     * @return list<OnboardingStep>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::Basics => [],
            self::RoomTypes, self::Taxes, self::PaymentMethods, self::Companies, self::Discounts, self::CheckInEssentials, self::Team, self::ChannelManager => [self::Basics],
            self::Rooms => [self::RoomTypes],
            self::BasicRate => [self::RoomTypes, self::Taxes],
            self::ChargeItems => [self::Taxes],
            self::TestDrive, self::Review => array_values(array_filter(
                self::cases(),
                fn (self $step): bool => $step->isRequired(),
            )),
        };
    }

    /**
     * The rough time the owner needs for the step, in minutes. The sum of the required steps is about 15 minutes.
     */
    public function estimatedMinutes(): int
    {
        return match ($this) {
            self::Basics => 2,
            self::RoomTypes => 2,
            self::Rooms => 2,
            self::Taxes => 1,
            self::PaymentMethods => 1,
            self::Companies => 2,
            self::BasicRate => 3,
            self::ChargeItems => 2,
            self::Discounts => 1,
            self::CheckInEssentials => 2,
            self::Team => 2,
            self::ChannelManager => 2,
            self::TestDrive => 5,
            self::Review => 1,
        };
    }

    /**
     * The route name of the step's page, or null while the page does not exist.
     */
    public function routeName(): ?string
    {
        return match ($this) {
            self::Basics => 'onboarding.basics.edit',
            self::RoomTypes => 'onboarding.room-types.edit',
            self::Rooms => 'onboarding.rooms.edit',
            self::Review => 'onboarding.go-live.show',
            default => null,
        };
    }

    /**
     * The steps that have a page, in wizard order. The browser lists only these, so it has no dead links.
     *
     * @return list<OnboardingStep>
     */
    public static function listed(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $step): bool => $step->routeName() !== null,
        ));
    }

    /**
     * The next listed step after this one in wizard order, or null when this is the last listed step.
     */
    public function nextListed(): ?self
    {
        foreach (self::listed() as $step) {
            if ($step->number() > $this->number()) {
                return $step;
            }
        }

        return null;
    }

    public function check(): StepCheck
    {
        return match ($this) {
            self::Basics => new BasicsCheck,
            self::RoomTypes => new RoomTypesCheck,
            self::Rooms => new RoomsCheck,
            self::Taxes => new TaxesCheck,
            self::PaymentMethods => new PaymentMethodsCheck,
            self::Companies => new CompaniesCheck,
            self::BasicRate => new BasicRateCheck,
            self::ChargeItems => new ChargeItemsCheck,
            self::Discounts => new DiscountsCheck,
            self::CheckInEssentials => new CheckInEssentialsCheck,
            self::Team => new TeamCheck,
            self::ChannelManager => new ChannelManagerCheck,
            self::TestDrive => new TestDriveCheck,
            self::Review => new ReviewCheck,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $step): array => ['value' => $step->value, 'label' => $step->label()],
            self::cases(),
        );
    }
}
