<?php

namespace App\Services\Catalogues;

use App\Data\Catalogues\RoomRevenuePolicyPreviewData;
use App\Data\Catalogues\RoomRevenuePolicyPreviewLineData;
use App\Data\Catalogues\RoomRevenuePreviewData;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use App\Models\Tax;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * Shows what a policy would charge on a sample stay of three nights at 100.00, with the taxes of its code.
 * The policy does not need to be saved. The sample uses the booked rate, so a Current rate policy shows the same figures.
 */
class RoomRevenuePolicyPreview
{
    public const SAMPLE_AMOUNT = '100.00';

    public const SAMPLE_NIGHTS = 3;

    private const FOREIGN_NATIONALITY = 'SG';

    public function __construct(
        private PolicyChargeCalculator $calculator,
        private TaxCalculator $taxCalculator,
        private RoomRevenueTaxIds $roomRevenueTaxIds,
    ) {}

    /**
     * @param  list<int>|null  $taxIds  Unsaved tax choice of a policy with its own taxes. Null uses the saved taxes of the policy.
     */
    public function calculate(Property $property, RoomRevenuePolicy $policy, ?array $taxIds = null): RoomRevenuePolicyPreviewData
    {
        $night = Money::of(self::SAMPLE_AMOUNT, $property->settings->currency->getCurrencyCode());
        $taxes = $property->taxes()->whereKey($taxIds ?? $this->roomRevenueTaxIds->forPolicy($property, $policy))->get();

        $lines = array_map(
            fn (array $case): RoomRevenuePolicyPreviewLineData => $this->line($property, $policy, $taxes, $case['label'], $case['free'], $case['base']),
            $this->cases($policy, $night),
        );

        return new RoomRevenuePolicyPreviewData(self::SAMPLE_AMOUNT, self::SAMPLE_NIGHTS, $lines);
    }

    /**
     * @return list<array{label: string, free: bool, base: Money}>
     */
    private function cases(RoomRevenuePolicy $policy, Money $night): array
    {
        $base = $night->multipliedBy($this->nightsCovered($policy->applies_to));

        return match ($policy->code) {
            SystemTransactionCode::CancellationRevenue => $this->cancellationCases($policy, $base),
            SystemTransactionCode::NoShowRevenue => [['label' => __('Guest does not arrive'), 'free' => false, 'base' => $base]],
            SystemTransactionCode::EarlyDepartureRevenue => [['label' => __('Guest leaves after 1 of :nights nights', ['nights' => self::SAMPLE_NIGHTS]), 'free' => false, 'base' => $base]],
            default => [['label' => __('Guest checks out late'), 'free' => false, 'base' => $base]],
        };
    }

    /**
     * @return list<array{label: string, free: bool, base: Money}>
     */
    private function cancellationCases(RoomRevenuePolicy $policy, Money $base): array
    {
        if ($policy->free_until_days === null) {
            return [['label' => __('Cancelled before arrival'), 'free' => false, 'base' => $base]];
        }

        $days = array_values(array_unique(array_filter(
            [$policy->free_until_days, $policy->free_until_days - 1],
            fn (int $days): bool => $days >= 0,
        )));

        return array_map(fn (int $count): array => [
            'label' => __('Cancelled :days days before arrival', ['days' => $count]),
            'free' => $this->calculator->isFreeWhenCancelledDaysBefore($policy, $count),
            'base' => $base,
        ], $days);
    }

    private function nightsCovered(PolicyScope $scope): int
    {
        return match ($scope) {
            PolicyScope::WholeStay => self::SAMPLE_NIGHTS,
            PolicyScope::UnusedNights => self::SAMPLE_NIGHTS - 1,
            PolicyScope::FirstNight, PolicyScope::OneCharge => 1,
        };
    }

    /**
     * @param  Collection<int, Tax>  $taxes
     */
    private function line(Property $property, RoomRevenuePolicy $policy, Collection $taxes, string $label, bool $free, Money $base): RoomRevenuePolicyPreviewLineData
    {
        $amount = $free ? Money::zero($base->getCurrency()) : $this->calculator->amount($policy, $base);
        $steps = $this->steps($policy, $base, $amount, $free);

        if ($amount->isZero()) {
            return new RoomRevenuePolicyPreviewLineData($label, true, null, null, null, $steps);
        }

        $local = $this->taxCalculator->calculateWithTaxes($property, $taxes, $amount, '1', $property->country_code);
        $foreign = $this->taxCalculator->calculateWithTaxes($property, $taxes, $amount, '1', self::FOREIGN_NATIONALITY);

        return new RoomRevenuePolicyPreviewLineData(
            $label,
            false,
            (string) $amount->getAmount(),
            $local->isCalculated() ? (string) $local->grossAmount->getAmount() : null,
            $foreign->isCalculated() ? (string) $foreign->grossAmount->getAmount() : null,
            $steps,
            RoomRevenuePreviewData::fromCalculation(['sampleAmount' => $amount, 'localGuest' => $local, 'foreignGuest' => $foreign]),
        );
    }

    /** @return list<string> */
    private function steps(RoomRevenuePolicy $policy, Money $base, Money $amount, bool $freeWindow): array
    {
        $currency = $base->getCurrency()->getCurrencyCode();
        $steps = [];

        if ($policy->code === SystemTransactionCode::EarlyDepartureRevenue) {
            $steps[] = __(':booked booked nights − 1 night stayed = :unused unused nights.', ['booked' => self::SAMPLE_NIGHTS, 'unused' => self::SAMPLE_NIGHTS - 1]);
        }

        if ($freeWindow) {
            $steps[] = __('Within the free cancellation window: :currency 0.00.', ['currency' => $currency]);
        } elseif ($policy->charge_mode === PolicyChargeMode::Free) {
            $steps[] = __('Free policy: :currency 0.00.', ['currency' => $currency]);
        } elseif ($policy->charge_mode === PolicyChargeMode::FixedAmount) {
            $steps[] = __('Fixed fee × 1 charge = :currency :amount.', ['currency' => $currency, 'amount' => (string) $amount->getAmount()]);
        } else {
            $nights = $this->nightsCovered($policy->applies_to);
            $steps[] = __($nights === 1 ? ':nights night × :currency :night = :currency :base.' : ':nights nights × :currency :night = :currency :base.', ['nights' => $nights, 'currency' => $currency, 'night' => self::SAMPLE_AMOUNT, 'base' => (string) $base->getAmount()]);
            $steps[] = __(':currency :base × :percent% = :currency :amount.', ['currency' => $currency, 'base' => (string) $base->getAmount(), 'percent' => $policy->percent, 'amount' => (string) $amount->getAmount()]);
        }

        if ($amount->isZero()) {
            $steps[] = __('No charge, so no taxes are added.');
        }

        return $steps;
    }
}
