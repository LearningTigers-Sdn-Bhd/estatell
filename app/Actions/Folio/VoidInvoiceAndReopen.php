<?php

namespace App\Actions\Folio;

use App\Actions\CityLedger\ArInvoiceResult;
use App\Actions\CityLedger\VoidArInvoice;
use App\Enums\SystemTransactionCode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Folio\FolioReopenRule;
use Illuminate\Support\Facades\DB;
use Throwable;

class VoidInvoiceAndReopen
{
    public function __construct(private VoidArInvoice $voidArInvoice, private ReopenFolio $reopenFolio, private FolioReopenRule $folioReopenRule, private ReverseLine $reverseLine) {}

    /**
     * Void the AR invoice that bills a Closed folio, then reopen the folio, in one transaction. A refusal of either
     * step rolls both back, writes and logs nothing, and comes back as the refusal code: an `ArInvoiceResult` code
     * from the void step (`not_voidable`, `already_void`) or a `FolioStatusResult` code from the reopen step. The folio's
     * active City ledger line (the DirectBillPayment line with no reversal) is then reversed with `ReverseLine`, so the
     * folio owes its charges again and settling again makes a new invoice. Both Actions open their own transaction, which nests safely here. Permissions
     * (`city-ledger.invoice` and `folio.reopen`) are checked by the route. See docs/13-folio/01-folios-and-lines.md.
     */
    public function __invoke(User $actor, Folio $folio): FolioStatusResult
    {
        DB::beginTransaction();

        try {
            $invoice = $this->folioReopenRule->billingInvoice($folio);

            if ($invoice === null) {
                DB::rollBack();

                return FolioStatusResult::refused(ArInvoiceResult::REFUSAL_NOT_VOIDABLE);
            }

            $voided = ($this->voidArInvoice)($actor, $invoice);

            if ($voided->isRefused()) {
                DB::rollBack();

                return FolioStatusResult::refused((string) $voided->refusal);
            }

            $reopened = ($this->reopenFolio)($actor, $folio);

            if ($reopened->isRefused()) {
                DB::rollBack();

                return $reopened;
            }

            $cityLedgerLines = FolioLine::query()
                ->where('folio_id', $folio->id)
                ->whereHas('transactionCode', fn ($codes) => $codes->where('system_key', SystemTransactionCode::DirectBillPayment->value))
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal')
                ->orderBy('id')
                ->get();

            foreach ($cityLedgerLines as $line) {
                $reversed = ($this->reverseLine)($actor, $line);

                if ($reversed->isRefused()) {
                    DB::rollBack();

                    return FolioStatusResult::refused((string) $reversed->refusal);
                }
            }

            DB::commit();

            return $reopened;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}
