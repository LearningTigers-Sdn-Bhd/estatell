<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\SaveOnboardingDiscounts;
use App\Data\Catalogues\DiscountChargeData;
use App\Data\Onboarding\OnboardingDiscountData;
use App\Data\Property\CurrencyData;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OnboardingStep;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveDiscountsRequest;
use App\Models\ChargeItem;
use App\Models\Discount;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DiscountController extends Controller
{
    /**
     * The property's discounts, active first, and the charge codes a Selected scope can pick: the active charge items
     * without Transport, plus any other charge item a discount already selects. A locked step opens as a preview.
     */
    public function edit(Property $property, OnboardingProgress $progress): Response
    {
        $blockingStep = $progress->blockingStep($property, OnboardingStep::Discounts);
        $roomCodeIds = $property->transactionCodes()
            ->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $discounts = $property->discounts()
            ->with(['transactionCode', 'selectedTransactionCodes'])
            ->orderByDesc('active')
            ->ordered()
            ->get();
        $selectedIds = $discounts->flatMap(fn (Discount $discount) => $discount->selectedTransactionCodes->pluck('id'))->unique()->all();

        return Inertia::render('onboarding/discounts', [
            'discounts' => $discounts->map(fn (Discount $discount): OnboardingDiscountData => OnboardingDiscountData::fromModel($discount, $roomCodeIds)),
            'charges' => $property->chargeItems()
                ->with('transactionCode')
                ->whereHas('transactionCode', fn (Builder $query) => $query->where('category', '!=', TransactionCategory::Transport->value))
                ->where(fn (Builder $query) => $query->where('active', true)->orWhereIn('transaction_code_id', $selectedIds))
                ->ordered()
                ->get()
                ->map(fn (ChargeItem $chargeItem): DiscountChargeData => DiscountChargeData::fromModel($chargeItem->transactionCode)),
            'takenCodes' => $property->transactionCodes()->pluck('code')->map(fn (string $code): string => mb_strtoupper($code))->values()->all(),
            'types' => DiscountType::options(),
            'scopes' => DiscountScope::options(),
            'currency' => CurrencyData::forProperty($property),
            'maxRows' => SaveDiscountsRequest::MAX_ROWS,
            'locked' => $blockingStep !== null,
            'blockedBy' => $blockingStep === null ? null : [
                'label' => $blockingStep->label(),
                'url' => $blockingStep->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            ],
        ]);
    }

    public function update(SaveDiscountsRequest $request, Property $property, SaveOnboardingDiscounts $saveDiscounts): RedirectResponse
    {
        $saveDiscounts($property, $request->user(), $request->rows());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discounts saved.')]);

        $next = OnboardingStep::Discounts->nextListed();

        return $next === null
            ? back()
            : to_route($next->routeName(), ['property' => $property->code]);
    }
}
