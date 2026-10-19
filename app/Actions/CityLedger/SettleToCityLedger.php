<?php

namespace App\Actions\CityLedger;

use App\Actions\Folio\CloseFolio;
use App\Enums\CompanyStatus;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Folio\InHouseUnits;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SettleToCityLedger
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FolioBalance $folioBalance,
        private CloseFolio $closeFolio,
        private CreateArInvoiceFromFolio $createArInvoiceFromFolio,
        private InHouseUnits $inHouseUnits,
        private IncomingScheduledCharges $incomingScheduledCharges,
    ) {}

    /**
     * Move the whole balance of an Open folio to the city ledger: post one negative `DIRECT_BILL` line on the business
     * date (key `city-ledger:{folio_id}:{n}`, n counting the folio's earlier City ledger lines, so a folio settled again after a void gets a new key), close the folio, and make the AR invoice, in one transaction. The folio
     * row is locked first and every check runs before the first write, so a refusal writes and logs nothing. A Master folio
     * is refused while a unit is CheckedIn (`units_in_house`), and any folio while a live unit still routes unposted
     * Scheduled rows to it (`folio_receives_scheduled_charges`), so no line is posted and then rolled back. It does
     * not use `RecordPayment`: there is no `payments` row and no receipt. It is never refused by the credit limit: settling
     * adds no debt, it moves an open folio balance into open AR, so the exposure is the same before and after. The folio
     * tab warns instead (`FolioData.settleCreditWarning`). Permissions are checked by the route. See docs/15-city-ledger/02-ar-invoices.md.
     */
    public function __invoke(User $actor, Folio $folio): ArInvoiceResult
    {
        return DB::transaction(function () use ($actor, $folio): ArInvoiceResult {
            $locked = Folio::query()->lockForUpdate()->findOrFail($folio->id);

            if ($locked->status !== FolioStatus::Open) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $rooms = $this->inHouseUnits->roomsFor($locked);

            if ($rooms !== []) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_UNITS_IN_HOUSE, $rooms);
            }

            $incoming = $this->incomingScheduledCharges->for($locked);

            if ($incoming !== []) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $incoming);
            }

            $company = $locked->payer_company_id === null
                ? null
                : Company::query()->where('property_id', $locked->property_id)->find($locked->payer_company_id);

            if ($company === null) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NO_COMPANY_PAYER);
            }

            if ($company->status !== CompanyStatus::Active || ! $company->direct_bill_enabled) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_DIRECT_BILL_NOT_ALLOWED);
            }

            $businessDate = $this->currentBusinessDate->date($locked->property);

            if ($businessDate === null) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $balance = $this->folioBalance->forFolio($locked);

            if (BigDecimal::of($balance)->isNegativeOrZero()) {
                return ArInvoiceResult::refused(ArInvoiceResult::REFUSAL_NOTHING_TO_SETTLE);
            }

            $code = TransactionCode::query()
                ->where('property_id', $locked->property_id)
                ->where('system_key', SystemTransactionCode::DirectBillPayment->value)
                ->firstOrFail();

            $attempt = 1 + FolioLine::query()
                ->where('folio_id', $locked->id)
                ->where('transaction_code_id', $code->id)
                ->whereNull('reversal_of_id')
                ->count();

            $line = new FolioLine([
                'transaction_code_id' => $code->id,
                'kind' => TransactionKind::Payment,
                'description' => $code->name,
                'quantity' => 1,
                'unit_amount' => '-'.$balance,
                'amount' => '-'.$balance,
            ]);
            $line->folio_id = $locked->id;
            $line->posting_date = $businessDate->toDateString();
            $line->service_date = $businessDate->toDateString();
            $line->idempotency_key = 'city-ledger:'.$locked->id.':'.$attempt;
            $line->user_id = $actor->id;
            $line->save();

            $closed = ($this->closeFolio)($actor, $locked);

            if ($closed->isRefused()) {
                throw new RuntimeException('A folio with a zero balance must close: '.$closed->refusal);
            }

            $invoice = ($this->createArInvoiceFromFolio)($actor, $closed->folio, $balance, $businessDate);

            return ArInvoiceResult::done($invoice);
        });
    }
}
