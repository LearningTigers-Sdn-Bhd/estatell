<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\GoLiveReviewData;
use App\Data\Onboarding\GoLiveReviewPhaseData;
use App\Data\Onboarding\GoLiveReviewRowData;
use App\Enums\CompanyStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\OnboardingPhase;
use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use App\Services\Property\PropertyToday;
use App\Services\Staff\PendingSignInUsers;
use Illuminate\Support\Str;

/**
 * Builds the Review page: one row per listed section, grouped by phase, with its state, a summary and its action.
 * Reads only. The state comes from OnboardingProgress, so the rows never disagree with the wizard.
 */
class GoLiveReview
{
    public function __construct(
        private OnboardingProgress $progress,
        private GoLiveReadiness $readiness,
        private GoLiveWarnings $warnings,
        private PendingSignInUsers $pendingSignInUsers,
        private PropertyToday $propertyToday,
        private CountryOptions $countryOptions,
        private GoLiveRequests $requests,
        private BasicRateRows $basicRateRows,
    ) {}

    /**
     * @param  bool  $withDetails  Add the extra lines an admin checks before approving.
     */
    public function forProperty(Property $property, bool $withDetails = false): GoLiveReviewData
    {
        $flagged = $this->requests->flaggedSteps($property);
        $states = $this->progress->states($property);
        $skipped = OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->get()
            ->map(fn (OnboardingSkip $skip): string => $skip->step->value)
            ->all();

        $phases = [];

        foreach (OnboardingPhase::cases() as $phase) {
            $rows = [];

            foreach ($phase->steps() as $step) {
                if ($step === OnboardingStep::Review || $step->routeName() === null) {
                    continue;
                }

                $rows[] = $this->row($property, $step, $states, $skipped, in_array($step->value, $flagged, true), $withDetails);
            }

            if ($rows !== []) {
                $phases[] = new GoLiveReviewPhaseData(value: $phase->value, label: $phase->label(), rows: $rows);
            }
        }

        $missing = collect($this->readiness->checks($property))
            ->reject(fn ($check): bool => $check->done)
            ->pluck('label')
            ->values()
            ->all();

        return new GoLiveReviewData(
            phases: $phases,
            warnings: $this->warnings->forProperty($property),
            ready: $missing === [],
            missing: $missing,
            signInCount: $this->pendingSignInUsers->count($property),
            firstBusinessDate: $this->propertyToday->date($property)->translatedFormat('j M Y'),
        );
    }

    /**
     * @param  array<string, StepState>  $states
     * @param  list<string>  $skipped
     */
    private function row(Property $property, OnboardingStep $step, array $states, array $skipped, bool $flagged, bool $withDetails): GoLiveReviewRowData
    {
        $state = $states[$step->value];
        $isSkipped = $state === StepState::Done && in_array($step->value, $skipped, true);
        $blocker = $state === StepState::Locked ? $this->progress->blockingStepIn($states, $step) : null;
        $target = $blocker ?? $step;

        [$actionLabel, $target] = match (true) {
            $state === StepState::Locked => ['Finish →', $target],
            $state === StepState::NeedsAttention => ['Fix →', $step],
            $state === StepState::Done && $isSkipped => ['Add', $step],
            $state === StepState::Done => ['Edit', $step],
            ! $step->isRequired() && $state === StepState::NotStarted => ['Add', $step],
            default => ['Finish →', $step],
        };

        if ($flagged && $state === StepState::Done) {
            $actionLabel = 'Fix →';
        }

        return new GoLiveReviewRowData(
            value: $step->value,
            label: $step->label(),
            state: $state,
            isRequired: $step->isRequired(),
            skipped: $isSkipped,
            summary: $this->summary($property, $step, $state, $isSkipped, $blocker),
            actionLabel: $actionLabel,
            actionUrl: route($target->routeName(), ['property' => $property->code], absolute: false),
            flagged: $flagged,
            details: $withDetails && ! $isSkipped ? $this->details($property, $step) : [],
        );
    }

    /**
     * The lines only the admin review shows: registration numbers, licences, tax rates and prices.
     *
     * @return list<string>
     */
    private function details(Property $property, OnboardingStep $step): array
    {
        return match ($step) {
            OnboardingStep::Basics => array_values(array_filter([
                $property->legal_name !== null ? __('Registered name: :name', ['name' => $property->legal_name]) : null,
                $property->registration_number !== null ? __('Registration number: :number', ['number' => $property->registration_number]) : null,
                $property->tin !== null ? __('TIN: :tin', ['tin' => $property->tin]) : null,
                ...$property->licences()->ordered()->get()->map(fn ($licence): string => __('Licence: :issuer :number', ['issuer' => $licence->issuer, 'number' => $licence->number]))->all(),
            ])),
            OnboardingStep::Taxes => $property->taxes()->where('enabled', true)->orderBy('name')->get()
                ->map(fn ($tax): string => $tax->name.' '.($tax->rate_type === TaxRateType::Percent ? rtrim(rtrim((string) $tax->rate, '0'), '.').'%' : (string) $tax->rate).($tax->registration_number !== null ? ' · '.__('Reg. :number', ['number' => $tax->registration_number]) : ''))
                ->all(),
            OnboardingStep::BasicRate => $this->basicRateRows->forProperty($property)['rows']
                ->map(fn ($row): string => __(':name: weekday :weekday, weekend :weekend', ['name' => $row->name, 'weekday' => $row->weekday ?? '-', 'weekend' => $row->weekend ?? '-']))
                ->all(),
            default => [],
        };
    }

    private function summary(Property $property, OnboardingStep $step, StepState $state, bool $skipped, ?OnboardingStep $blocker): string
    {
        if ($blocker !== null) {
            return "Finish {$blocker->label()} first.";
        }

        if ($skipped) {
            return $step === OnboardingStep::Taxes ? "We don't charge tax." : 'Skipped.';
        }

        $settings = $property->settings;

        return match ($step) {
            OnboardingStep::Basics => implode(' · ', array_filter([
                $property->name,
                $this->countryOptions->name($property->country_code),
                $settings?->currency?->getCurrencyCode(),
            ])),
            OnboardingStep::CheckInEssentials => $settings === null
                ? ''
                : trim(sprintf(
                    'Check-in %s · Check-out %s%s',
                    substr((string) $settings->check_in_time, 0, 5),
                    substr((string) $settings->check_out_time, 0, 5),
                    (float) $settings->security_deposit_amount > 0
                        ? " · Deposit {$settings->currency->getCurrencyCode()} {$settings->security_deposit_amount}"
                        : '',
                )),
            OnboardingStep::Team => $this->count(
                $property->users()
                    ->whereNull('deactivated_at')
                    ->whereDoesntHave('roles', fn ($query) => $query->where('default_role', DefaultRoleEnum::Owner->value))
                    ->count(),
                'staff member',
            ),
            OnboardingStep::RoomTypes => $this->count($property->roomTypes()->active()->count(), 'room type'),
            OnboardingStep::Rooms => $this->count($property->rooms()->active()->count(), 'room'),
            OnboardingStep::Taxes => $this->roomTaxesSummary($property),
            OnboardingStep::PaymentMethods => $this->count($property->paymentMethods()->active()->count(), 'payment method'),
            OnboardingStep::Companies => $this->count($property->companies()->where('status', CompanyStatus::Active->value)->count(), 'company', 'companies'),
            OnboardingStep::BasicRate => match ($state) {
                StepState::Done => 'Priced for the next 30 days.',
                StepState::InProgress => 'Some room types are priced.',
                default => 'No prices yet.',
            },
            OnboardingStep::ChargeItems => $this->count($property->chargeItems()->active()->count(), 'charge item'),
            OnboardingStep::Discounts => $this->count($property->discounts()->active()->count(), 'discount'),
            OnboardingStep::ChannelManager => $this->count($property->otaCredentials()->count(), 'channel connected', 'channels connected'),
            default => '',
        };
    }

    private function roomTaxesSummary(Property $property): string
    {
        $names = $property->taxes()
            ->where('enabled', true)
            ->whereHas('applicableTransactionCodes', fn ($query) => $query->where('system_key', SystemTransactionCode::RoomRevenue->value))
            ->pluck('name')
            ->all();

        return $names === []
            ? 'No tax applies to rooms yet.'
            : 'Applies to rooms: '.implode(', ', $names).'.';
    }

    private function count(int $count, string $singular, ?string $plural = null): string
    {
        return $count.' '.($count === 1 ? $singular : ($plural ?? Str::plural($singular)));
    }
}
