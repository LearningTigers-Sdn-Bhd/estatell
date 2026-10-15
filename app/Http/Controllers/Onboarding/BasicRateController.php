<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingBasicRate;
use App\Data\Onboarding\OnboardingMoreRateData;
use App\Data\Property\CurrencyData;
use App\Enums\OnboardingStep;
use App\Enums\PricingBasis;
use App\Enums\WeekendNights;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\PreviewBasicRateRequest;
use App\Http\Requests\Onboarding\SaveBasicRateRequest;
use App\Models\Property;
use App\Services\Onboarding\BasicRateRows;
use App\Services\Onboarding\MoreRateRows;
use App\Services\Onboarding\OnboardingBasicRatePreview;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BasicRateController extends Controller
{
    /**
     * The room types sold by room with their saved weekday and weekend price, the weekend nights, and the guest pays
     * preview of the saved prices. A locked step opens as a preview with no rows to price.
     */
    public function edit(Property $property, OnboardingProgress $progress, BasicRateRows $basicRateRows, MoreRateRows $moreRateRows, OnboardingBasicRatePreview $preview): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::BasicRate);
        $table = $basicRateRows->forProperty($property);
        $more = $moreRateRows->forProperty($property);
        $basicRows = array_values($table['rows']->map(fn ($row): array => [
            'room_type_id' => $row->roomTypeId,
            'price_by' => $row->priceBy,
            'weekday' => $row->weekday,
            'weekend' => $row->weekend,
        ])->all());

        return Inertia::render('onboarding/basic-rate', [
            'rows' => $table['rows'],
            'sameEveryNight' => $table['sameEveryNight'] && $more['sameEveryNight'],
            'weekendNights' => $property->settings?->weekend_nights->value ?? WeekendNights::FriSat->value,
            'weekendNightsOptions' => WeekendNights::options(),
            'priceByOptions' => [
                ['value' => PricingBasis::PerRoom->value, 'label' => __('Room')],
                ['value' => PricingBasis::PerGuest->value, 'label' => __('Guest')],
            ],
            'preview' => $property->settings === null ? ['rows' => [], 'followers' => []] : [
                'rows' => $preview->forRows($property, $basicRows),
                'followers' => $preview->followerExamples($property, $basicRows, array_map(fn (OnboardingMoreRateData $row): array => [
                    'key' => $row->key,
                    'parent_basis' => $row->parentBasis,
                    'direction' => $row->direction,
                    'unit' => $row->unit,
                    'amount' => $row->amount,
                    'room_type_ids' => $row->roomTypeIds,
                ], array_values(array_filter($more['rows'], fn (OnboardingMoreRateData $row): bool => $row->mode === 'follows')))),
            ],
            'moreRates' => $more['rows'],
            'currency' => CurrencyData::forProperty($property),
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    /**
     * Calculate what a guest pays for the table as it is on screen, before it is saved.
     */
    public function preview(PreviewBasicRateRequest $request, Property $property, OnboardingBasicRatePreview $preview): JsonResponse
    {
        return response()->json([
            'rows' => $preview->forRows($property, $request->rows()),
            'followers' => $preview->followerExamples($property, $request->rows(), $request->followers()),
        ]);
    }

    public function update(SaveBasicRateRequest $request, Property $property, SaveOnboardingBasicRate $saveBasicRate): RedirectResponse
    {
        if (! $saveBasicRate($property, $request->user(), $request->weekendNights(), $request->sameEveryNight(), $request->rows(), $request->moreRates())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The rates could not be saved. Reload the page and try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Basic rate saved.')]);

        $next = OnboardingStep::BasicRate->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
