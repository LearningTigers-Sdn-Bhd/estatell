<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\DeleteOnboardingTax;
use App\Actions\Onboarding\SaveOnboardingTaxes;
use App\Data\Catalogues\RoomRevenuePreviewData;
use App\Data\Onboarding\OnboardingTaxData;
use App\Data\Property\CurrencyData;
use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveTaxesRequest;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenuePreview;
use App\Services\Catalogues\RoomRevenueTaxIds;
use App\Services\Guests\CountryOptions;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\OnboardingTaxPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    /**
     * The property's taxes with whether each applies to rooms, the sample room night for the saved taxes and the
     * "We don't charge tax" answer. A locked step opens as a preview, and before Basics is saved there are no
     * settings, so the preview and the currency are null.
     */
    public function edit(Property $property, OnboardingProgress $progress, RoomRevenueTaxIds $roomRevenueTaxIds, RoomRevenuePreview $roomRevenuePreview, CountryOptions $countries): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Taxes);
        $roomTaxIds = $roomRevenueTaxIds->forRoom($property);

        return Inertia::render('onboarding/taxes', [
            'taxes' => $property->taxes()->with('transactionCode')->orderBy('id')->get()
                ->map(fn (Tax $tax): OnboardingTaxData => OnboardingTaxData::fromModel($tax, in_array($tax->id, $roomTaxIds, true))),
            'noTax' => OnboardingSkip::query()->where('property_id', $property->id)->where('step', OnboardingStep::Taxes)->exists(),
            'preview' => $property->settings === null
                ? null
                : RoomRevenuePreviewData::fromCalculation($roomRevenuePreview->calculate($property, $roomTaxIds)),
            'currency' => CurrencyData::forProperty($property),
            'countryName' => $countries->name($property->country_code),
            'isMalaysia' => mb_strtoupper((string) $property->country_code) === 'MY',
            'maxRows' => SaveTaxesRequest::MAX_ROWS,
            'chargeItemsUrl' => $this->urlOf(OnboardingStep::ChargeItems, $property, ['from' => 'taxes']),
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $this->urlOf($blockingStep, $property),
            ],
        ]);
    }

    /**
     * Calculate the sample room night for the table as it is on screen, before it is saved.
     */
    public function preview(SaveTaxesRequest $request, Property $property, OnboardingTaxPreview $taxPreview): JsonResponse
    {
        return response()->json($taxPreview->forRows($property, $request->rows()));
    }

    public function update(SaveTaxesRequest $request, Property $property, SaveOnboardingTaxes $saveTaxes): RedirectResponse
    {
        $saveTaxes($property, $request->user(), $request->rows(), $request->noTax());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Taxes saved.')]);

        $next = OnboardingStep::Taxes->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }

    public function destroy(Request $request, Property $property, int $tax, OnboardingProgress $progress, DeleteOnboardingTax $deleteTax): RedirectResponse
    {
        $model = $property->taxes()->with('transactionCode')->findOrFail($tax);

        if ($progress->blockingStep($property, OnboardingStep::Taxes) !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Finish the earlier section first.')]);

            return back();
        }

        if (! $deleteTax($request->user(), $model)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __(':name cannot be removed. Turn it off instead.', ['name' => $model->name])]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', ['name' => $model->name])]);

        return back();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function urlOf(OnboardingStep $step, Property $property, array $query = []): string
    {
        return route($step->routeName(), ['property' => $property->code, ...$query], absolute: false);
    }
}
