<?php

namespace App\Actions\CityLedger;

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\CompanyStatus;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\User;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AddOldSystemInvoice
{
    public function __construct(
        private MigrationWindow $migrationWindow,
        private FirstBusinessDate $firstBusinessDate,
    ) {}

    /**
     * Bring an invoice a company still owes from the old PMS into the city ledger: an Open invoice with no folio, the
     * old number, the old dates and what is still unpaid, marked `migrated`. The company row is locked first and every
     * check runs before the first write, so a refusal writes and logs nothing. It needs an open migration window and
     * an Active company of the property. Permissions (`folio.manual`) are checked by the route. See
     * docs/15-city-ledger/05-old-system-balances.md.
     */
    public function __invoke(User $actor, Company $company, string $number, CarbonImmutable $issuedOn, CarbonImmutable $dueOn, string $amount, string $reason): ArInvoiceResult
    {
        return DB::transaction(function () use ($actor, $company, $number, $issuedOn, $dueOn, $amount, $reason): ArInvoiceResult {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $property = $locked->property;

            if (! $this->migrationWindow->isOpen($property)) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_MIGRATION_WINDOW_CLOSED);
            }

            if ($locked->status !== CompanyStatus::Active) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_COMPANY_NOT_AVAILABLE);
            }

            $number = trim($number);
            $reason = trim($reason);
            $goLive = $this->firstBusinessDate->date($property);

            if ($goLive === null || $issuedOn->startOfDay()->greaterThanOrEqualTo($goLive)) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_ISSUED_AFTER_GO_LIVE);
            }

            if ($dueOn->startOfDay()->lessThan($issuedOn->startOfDay())) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_DUE_BEFORE_ISSUE);
            }

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || BigDecimal::of($amount)->isNegativeOrZero()) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_AMOUNT_INVALID);
            }

            if ($number === '') {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NUMBER_REQUIRED);
            }

            if ($reason === '') {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_REASON_REQUIRED);
            }

            $taken = ArInvoice::query()
                ->where('property_id', $locked->property_id)
                ->where('type', ArInvoiceType::Invoice)
                ->where('status', '<>', ArInvoiceStatus::Void)
                ->where('number', $number)
                ->exists();

            if ($taken) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NUMBER_TAKEN);
            }

            $invoice = new ArInvoice([
                'company_id' => $locked->id,
                'type' => ArInvoiceType::Invoice,
                'number' => $number,
                'issued_on' => $issuedOn->startOfDay(),
                'due_on' => $dueOn->startOfDay(),
                'amount' => (string) BigDecimal::of($amount)->toScale(2),
                'reason' => $reason,
                'migrated' => true,
                'status' => ArInvoiceStatus::Open,
                'created_by' => $actor->id,
            ]);
            $invoice->property_id = $locked->property_id;
            $invoice->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('city_ledger.old_invoice_added')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'ar_invoice_id' => $invoice->id,
                    'number' => $invoice->number,
                    'issued_on' => $issuedOn->toDateString(),
                    'due_on' => $dueOn->toDateString(),
                    'amount' => $invoice->amount,
                    'reason' => $reason,
                ])
                ->log('Old system invoice added');

            return ArInvoiceResult::done($invoice);
        });
    }
}
