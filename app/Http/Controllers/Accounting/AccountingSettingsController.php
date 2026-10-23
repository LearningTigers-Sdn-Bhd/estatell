<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\PushDailyJournal;
use App\Actions\Accounting\ReconcileCompanyBilling;
use App\Actions\Accounting\SaveAccountingConnection;
use App\Actions\Accounting\SaveAccountMappings;
use App\Actions\Accounting\TestAccountingConnection;
use App\Data\Accounting\AccountingConnectionData;
use App\Data\Accounting\AccountingDocumentData;
use App\Data\Accounting\AccountingMappingData;
use App\Data\Accounting\AccountingProviderData;
use App\Data\Accounting\AccountingSetupData;
use App\Data\Accounting\AccountingSyncData;
use App\Enums\AccountingProvider;
use App\Enums\AccountingSyncStatus;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\SaveAccountingConnectionRequest;
use App\Http\Requests\Accounting\SaveAccountMappingsRequest;
use App\Models\AccountingDocument;
use App\Models\AccountingSync;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Services\Accounting\AccountingSetup;
use App\Services\Accounting\MappableTransactionCodes;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSettingsController extends Controller
{
    /**
     * The order of the groups on the Accounts tab: money earned, tax, money received, then adjustments. The key is the
     * kind's value and the number is its place.
     *
     * @var array<string, int>
     */
    private const KIND_ORDER = [
        TransactionKind::Charge->value => 0,
        TransactionKind::Tax->value => 1,
        TransactionKind::Payment->value => 2,
        TransactionKind::Adjustment->value => 3,
    ];

    /**
     * The Connection tab: the connection without its key and the providers to pick from.
     */
    public function edit(Property $property, AccountingSetup $setup): Response
    {
        $connection = $property->accountingConnection;

        return Inertia::render('settings/accounting/index', [
            'setup' => AccountingSetupData::forProperty($property, $setup),
            'providers' => array_map(AccountingProviderData::fromEnum(...), AccountingProvider::cases()),
            'connection' => $connection === null ? null : AccountingConnectionData::fromModel($connection),
        ]);
    }

    /**
     * The Accounts tab: every mappable code, loaded once. Mapping needs no connection, so it works before one is saved.
     */
    public function accounts(Property $property, AccountingSetup $setup, MappableTransactionCodes $mappableCodes): Response
    {
        return Inertia::render('settings/accounting/accounts', [
            'setup' => AccountingSetupData::forProperty($property, $setup),
            'mappings' => $mappableCodes->query($property)
                ->orderBy('code')
                ->get()
                ->sortBy(fn (TransactionCode $code): int => self::KIND_ORDER[$code->kind->value])
                ->values()
                ->map(fn (TransactionCode $code): AccountingMappingData => AccountingMappingData::fromModel($code)),
        ]);
    }

    /**
     * The History tab: the last 30 pushed business dates.
     */
    public function history(Property $property, AccountingSetup $setup): Response
    {
        return Inertia::render('settings/accounting/history', [
            'setup' => AccountingSetupData::forProperty($property, $setup),
            'syncs' => AccountingSync::query()
                ->where('property_id', $property->id)
                ->with('businessDate')
                ->latest('business_date_id')
                ->limit(30)
                ->get()
                ->map(fn (AccountingSync $sync): AccountingSyncData => AccountingSyncData::fromModel($sync)),
            'documents' => AccountingDocument::query()
                ->where('property_id', $property->id)
                ->with(['arInvoice.company', 'arPayment.company'])
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (AccountingDocument $document): AccountingDocumentData => AccountingDocumentData::fromModel($document)),
        ]);
    }

    public function update(SaveAccountingConnectionRequest $request, Property $property, SaveAccountingConnection $saveAccountingConnection): RedirectResponse
    {
        $saveAccountingConnection($property, $request->user(), $request->connection());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Accounting connection saved.')]);

        return to_route('settings.accounting.edit', $property);
    }

    public function mappings(SaveAccountMappingsRequest $request, Property $property, SaveAccountMappings $saveAccountMappings): RedirectResponse
    {
        $saveAccountMappings($property, $request->user(), $request->accounts());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account mappings saved.')]);

        return to_route('settings.accounting.accounts', $property);
    }

    public function test(Property $property, TestAccountingConnection $testAccountingConnection): RedirectResponse
    {
        $connection = $property->accountingConnection;

        if ($connection === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save the connection first.')]);

            return to_route('settings.accounting.edit', $property);
        }

        $error = $testAccountingConnection($connection);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Connected. The guest ledger account and every mapped account exist.')]
            : ['type' => 'error', 'message' => $error]);

        return to_route('settings.accounting.edit', $property);
    }

    /**
     * Send the property's company invoices, credit notes and payments now, instead of waiting for the queue.
     */
    public function syncBilling(Property $property, ReconcileCompanyBilling $reconcileCompanyBilling): RedirectResponse
    {
        try {
            $result = $reconcileCompanyBilling($property);
        } catch (LockTimeoutException) {
            $result = null;
        }

        Inertia::flash('toast', $result === null
            ? ['type' => 'error', 'message' => __('Company billing is not switched on, or another sync is running. Try again in a minute.')]
            : ['type' => $result['failed'] === 0 ? 'success' : 'error', 'message' => __(':changed sent or changed, :failed failed.', $result)]);

        return to_route('settings.accounting.history', $property);
    }

    public function retry(Property $property, AccountingSync $accountingSync, PushDailyJournal $pushDailyJournal): RedirectResponse
    {
        $accountingSync->load('businessDate');
        $result = $accountingSync->status->canTransitionTo(AccountingSyncStatus::Pushed)
            ? $pushDailyJournal($property, $accountingSync->businessDate)
            : null;

        Inertia::flash('toast', $result === null
            ? ['type' => 'error', 'message' => __('This date cannot be pushed again.')]
            : ['type' => $result->error === null ? 'success' : 'error', 'message' => $result->error ?? __('Journal pushed.')]);

        return to_route('settings.accounting.history', $property);
    }
}
