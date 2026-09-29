<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateTax;
use App\Actions\Catalogues\UpdateTax;
use App\Data\Catalogues\TaxData;
use App\Data\Catalogues\TaxTransactionCodeData;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\TaxRequest;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
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
            ->with(['transactionCode', 'applicableTransactionCodes'])
            ->orderByDesc('enabled')->orderBy('name')->orderBy('id')->get();
        $currentCodeIds = $taxes->flatMap->applicableTransactionCodes->pluck('id')->unique();

        return Inertia::render('settings/taxes/index', [
            'taxes' => $taxes->map(fn (Tax $tax): TaxData => TaxData::fromModel($tax)),
            'transactionCodes' => $property->transactionCodes()
                ->where('kind', TransactionKind::Charge)
                ->where(function ($query) use ($currentCodeIds): void {
                    $query->active()->orWhereIn('id', $currentCodeIds);
                })
                ->orderByDesc('active')->orderBy('code')->get()
                ->map(fn (TransactionCode $code): TaxTransactionCodeData => TaxTransactionCodeData::fromModel($code)),
            'rateTypes' => TaxRateType::options(),
            'currency' => $property->settings->currency,
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
