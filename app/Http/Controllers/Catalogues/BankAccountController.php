<?php

namespace App\Http\Controllers\Catalogues;

use App\Actions\Catalogues\CreateBankAccount;
use App\Actions\Catalogues\ReorderBankAccounts;
use App\Actions\Catalogues\UpdateBankAccount;
use App\Data\Catalogues\BankAccountData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogues\BankAccountRequest;
use App\Http\Requests\Catalogues\ReorderBankAccountsRequest;
use App\Models\Property;
use App\Models\PropertyBankAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Settings › Bank accounts. See docs/06-catalogues/07-bank-accounts.md. */
class BankAccountController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/bank-accounts/index', [
            'bankAccounts' => $property->bankAccounts()
                ->orderByDesc('active')
                ->ordered()
                ->get()
                ->map(fn (PropertyBankAccount $bankAccount): BankAccountData => BankAccountData::fromModel($bankAccount)),
        ]);
    }

    public function store(BankAccountRequest $request, Property $property, CreateBankAccount $createBankAccount): RedirectResponse
    {
        $bankAccount = $createBankAccount($property, $request->user(), $request->bankAccount());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $bankAccount->bank_name])]);

        return to_route('settings.bank-accounts.index', $property);
    }

    public function update(
        BankAccountRequest $request,
        Property $property,
        PropertyBankAccount $bankAccount,
        UpdateBankAccount $updateBankAccount,
    ): RedirectResponse {
        $updateBankAccount($request->user(), $bankAccount, $request->bankAccount());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $request->string('bank_name')])]);

        return to_route('settings.bank-accounts.index', $property);
    }

    public function reorder(
        ReorderBankAccountsRequest $request,
        Property $property,
        ReorderBankAccounts $reorderBankAccounts,
    ): RedirectResponse {
        if (! $reorderBankAccounts($property, $request->user(), $request->orderedIds())) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('The bank accounts changed. Reload the page and try again.'),
            ]);
        }

        return to_route('settings.bank-accounts.index', $property);
    }
}
