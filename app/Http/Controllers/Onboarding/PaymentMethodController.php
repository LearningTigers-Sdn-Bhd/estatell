<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingPaymentMethods;
use App\Data\Onboarding\OnboardingPaymentMethodData;
use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SavePaymentMethodsRequest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    /**
     * The property's payment methods in settings order, without OTA collected (channels come after go-live), and the
     * types a new row can take. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::PaymentMethods);

        return Inertia::render('onboarding/payment-methods', [
            'paymentMethods' => $property->paymentMethods()
                ->where('type', '!=', PaymentMethodType::OtaCollected->value)
                ->ordered()
                ->get()
                ->map(fn (PaymentMethod $paymentMethod): OnboardingPaymentMethodData => OnboardingPaymentMethodData::fromModel($paymentMethod)),
            'typeOptions' => PaymentMethodType::addableOptions(),
            'maxRows' => SavePaymentMethodsRequest::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SavePaymentMethodsRequest $request, Property $property, SaveOnboardingPaymentMethods $savePaymentMethods): RedirectResponse
    {
        $savePaymentMethods($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment methods saved.')]);

        $next = OnboardingStep::PaymentMethods->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
