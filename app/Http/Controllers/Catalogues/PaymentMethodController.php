<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreatePaymentMethod;
use App\Actions\Catalogues\ReorderPaymentMethods;
use App\Actions\Catalogues\UpdatePaymentMethod;
use App\Data\Catalogues\PaymentMethodData;
use App\Data\Catalogues\SurchargeChargeItemData;
use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\PaymentMethodRequest;
use App\Http\Requests\Catalogues\ReorderPaymentMethodsRequest;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    /** Load the bounded catalogue once for browser-side search and filtering. */
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        $paymentMethods = $property->paymentMethods()
            ->with(['transactionCode', 'surchargeChargeItem'])
            ->orderByDesc('active')
            ->ordered()
            ->get();
        $currentChargeItemIds = $paymentMethods->pluck('surcharge_charge_item_id')->filter()->unique();

        return Inertia::render('settings/payment-methods/index', [
            'paymentMethods' => $paymentMethods->map(
                fn (PaymentMethod $paymentMethod): PaymentMethodData => PaymentMethodData::fromModel($paymentMethod),
            ),
            'chargeItems' => $property->chargeItems()
                ->with('transactionCode')
                ->where(function ($query) use ($currentChargeItemIds): void {
                    $query->active()->orWhereIn('id', $currentChargeItemIds);
                })
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): SurchargeChargeItemData => SurchargeChargeItemData::fromModel($chargeItem)),
            'types' => PaymentMethodType::addableOptions(),
            'surchargeTypes' => SurchargeType::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function store(PaymentMethodRequest $request, Property $property, CreatePaymentMethod $createPaymentMethod): RedirectResponse
    {
        $paymentMethod = $createPaymentMethod($property, $request->user(), $request->validated());

        if (! $paymentMethod instanceof PaymentMethod) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The surcharge charge item is no longer available.')]);

            return to_route('settings.payment-methods.index', $property);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $paymentMethod->name])]);

        return to_route('settings.payment-methods.index', $property);
    }

    public function update(
        PaymentMethodRequest $request,
        Property $property,
        PaymentMethod $paymentMethod,
        UpdatePaymentMethod $updatePaymentMethod,
    ): RedirectResponse {
        if (! $updatePaymentMethod($request->user(), $paymentMethod, $request->validated())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The surcharge charge item is no longer available.')]);

            return to_route('settings.payment-methods.index', $property);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':name saved.', ['name' => $request->string('name')->trim()]),
        ]);

        return to_route('settings.payment-methods.index', $property);
    }

    public function reorder(
        ReorderPaymentMethodsRequest $request,
        Property $property,
        ReorderPaymentMethods $reorderPaymentMethods,
    ): RedirectResponse {
        if (! $reorderPaymentMethods($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('The payment methods changed. Reload the page and try again.'),
            ]);
        }

        return to_route('settings.payment-methods.index', $property);
    }
}
