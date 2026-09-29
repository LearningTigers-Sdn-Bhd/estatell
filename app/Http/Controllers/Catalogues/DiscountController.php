<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateDiscount;
use App\Actions\Catalogues\ReorderDiscounts;
use App\Actions\Catalogues\UpdateDiscount;
use App\Data\Catalogues\DiscountChargeData;
use App\Data\Catalogues\DiscountData;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\DiscountRequest;
use App\Http\Requests\Catalogues\ReorderDiscountsRequest;
use App\Models\Discount;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DiscountController extends Controller
{
    /**
     * Load the property's bounded discount catalogue once for browser-side filtering.
     */
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        return Inertia::render('settings/discounts/index', [
            'discounts' => $property->discounts()
                ->with(['transactionCode', 'selectedTransactionCodes'])
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (Discount $discount): DiscountData => DiscountData::fromModel($discount)),
            'charges' => $property->transactionCodes()
                ->where('kind', TransactionKind::Charge)
                ->orderByDesc('active')
                ->orderBy('name')
                ->get()
                ->map(fn (TransactionCode $transactionCode): DiscountChargeData => DiscountChargeData::fromModel($transactionCode)),
            'types' => DiscountType::options(),
            'scopes' => DiscountScope::options(),
            'currency' => $property->settings->currency,
        ]);
    }

    public function store(DiscountRequest $request, Property $property, CreateDiscount $createDiscount): RedirectResponse
    {
        $discount = $createDiscount($property, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $discount->name])]);

        return to_route('settings.discounts.index', $property);
    }

    public function update(DiscountRequest $request, Property $property, Discount $discount, UpdateDiscount $updateDiscount): RedirectResponse
    {
        $updateDiscount($request->user(), $discount, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $discount->refresh()->name])]);

        return to_route('settings.discounts.index', $property);
    }

    public function reorder(ReorderDiscountsRequest $request, Property $property, ReorderDiscounts $reorderDiscounts): RedirectResponse
    {
        if (! $reorderDiscounts($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The discounts changed. Reload the page and try again.')]);
        }

        return to_route('settings.discounts.index', $property);
    }
}
