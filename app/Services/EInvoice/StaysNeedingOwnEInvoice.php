<?php

namespace App\Services\EInvoice;

use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Closed stays over RM10,000 that have no e-invoice yet. Since 1 January 2026 LHDN does not allow a sale over RM10,000
 * in a monthly summary, so each needs its own e-invoice with the guest's own tax number. A stay billed to a company is
 * e-invoiced from its City ledger invoice and is not listed. Only stays closed since e-invoicing was set up count. It
 * reads only. See docs/31-einvoice/01-lhdn-myinvois.md#stays-over-rm10000.
 */
class StaysNeedingOwnEInvoice
{
    /** LHDN's limit for one sale in a monthly summary, from 1 January 2026. */
    public const string LIMIT = '10000.00';

    public function __construct(private ReportableLines $reportableLines, private FolioEInvoiceLock $folioEInvoiceLock) {}

    /**
     * Whether this folio's total is over the limit, so it cannot go in a monthly summary.
     */
    public function isOverLimit(Folio $folio): bool
    {
        return $this->reportableLines->gross($folio)->isGreaterThan(self::LIMIT);
    }

    /**
     * @return list<array{folio_id: int, reservation_id: int, reference: string|null, guest: string|null, amount: string, closed_on: string|null}>
     */
    public function for(Property $property): array
    {
        $setting = $property->eInvoiceSetting;
        if ($setting === null || ! $setting->enabled) {
            return [];
        }

        // Totals in the database first, with the rule of `ReportableLines::gross` (no payments, no reversal lines, no
        // line a reversal cancels), so only stays over the limit are loaded.
        $overLimit = DB::table('folio_lines as line')
            ->join('folios', 'folios.id', '=', 'line.folio_id')
            ->where('folios.property_id', $property->id)
            ->where('folios.status', FolioStatus::Closed->value)
            ->where('line.kind', '<>', TransactionKind::Payment->value)
            ->whereNull('line.reversal_of_id')
            ->whereNotExists(fn ($reversal) => $reversal->from('folio_lines as reversal')->whereColumn('reversal.reversal_of_id', 'line.id'))
            ->groupBy('line.folio_id')
            ->havingRaw('sum(line.amount) > ?', [self::LIMIT])
            ->select('line.folio_id');

        $folios = $this->folioEInvoiceLock->whereStillToReport(Folio::query(), $property->id)
            ->where('property_id', $property->id)
            ->where('status', FolioStatus::Closed)
            ->where('closed_at', '>=', $setting->created_at)
            ->whereIn('id', $overLimit)
            ->with(['lines.transactionCode', 'reservation:id,reference', 'payerGuest:id,full_name'])
            ->orderBy('closed_at')
            ->get();

        $stays = [];
        foreach ($folios as $folio) {
            if (! $this->isOverLimit($folio)) {
                continue;
            }

            $stays[] = [
                'folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'reference' => $folio->reservation?->reference,
                'guest' => $folio->payerGuest?->full_name,
                'amount' => (string) $this->reportableLines->gross($folio),
                'closed_on' => $folio->closed_at?->toDateString(),
            ];
        }

        return $stays;
    }
}
