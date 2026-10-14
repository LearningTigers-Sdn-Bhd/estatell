<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateTax;
use App\Actions\Catalogues\UpdateTax;
use App\Data\Catalogues\TaxData;
use App\Data\Property\CurrencyData;
use App\Enums\TaxRateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\TaxRequest;
use App\Models\Property;
use App\Models\Tax;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    /** Load the bounded catalogue once for browser-side search and filtering. */
    public function index(Property $property): Response
    {
        abort_if($property->settings === null, 404);

        $taxes = $property->taxes()
            ->with(['transactionCode', 'applicableTransactionCodes.chargeItem'])
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->get();

        return Inertia::render('settings/taxes/index', [
            'taxes' => $taxes->map(fn (Tax $tax): TaxData => TaxData::fromModel($tax)),
            'rateTypes' => TaxRateType::options(),
            'currency' => CurrencyData::forProperty($property),
        ]);
    }

    public function store(TaxRequest $request, Property $property, CreateTax $createTax): RedirectResponse
    {
        $tax = $createTax($property, $request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $tax->name])]);

        return to_route('settings.taxes.index', $property);
    }

    public function update(TaxRequest $request, Property $property, Tax $tax, UpdateTax $updateTax): RedirectResponse
    {
        $updateTax($request->user(), $tax, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $tax->name])]);

        return to_route('settings.taxes.index', $property);
    }
}
