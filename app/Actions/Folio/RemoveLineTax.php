<?php

namespace App\Actions\Folio;

use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Folio\LineTaxes;
use App\Services\Folio\ManualBatches;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class RemoveLineTax
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private LineTaxes $lineTaxes,
        private ManualBatches $manualBatches,
        private CreateManualBatch $createManualBatch,
        private ReverseLine $reverseLine,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Reverse the chosen tax lines of a posted charge. Each reversal stands alone: the charge and its other tax lines
     * stay. This is one of two ways to reverse a tax line without its charge (`RecalculateLineTax` is the other);
     * `ReverseLine` still refuses a child line. A tax line id that is not a direct tax line of the charge is
     * `tax_not_on_line`, and one that is already reversed is `already_reversed`. A charge with a discount is refused
     * (`line_has_discount`). The folio row is locked first and every check runs before the first write, so a refusal
     * writes and logs nothing. The batch key is the form's UUID: a repeat returns the batch and writes nothing.
     * Permissions are checked by the route. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
     *
     * @param  list<int>  $taxLineIds
     */
    public function __invoke(User $actor, FolioLine $charge, array $taxLineIds, string $reason, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $charge, $taxLineIds, $reason, $key): PostingResult {
            $current = FolioLine::query()->findOrFail($charge->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);

            if (($replayed = $this->manualBatches->replay($folio, $key)) !== null) {
                return $replayed;
            }

            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $refusal = $this->lineTaxes->chargeRefusal($current, $folio);

            if ($refusal !== null) {
                return PostingResult::refused($refusal);
            }

            $taxLines = $current->children()->where('kind', TransactionKind::Tax)->whereIn('id', $taxLineIds)->orderBy('id')->get();

            if ($taxLineIds === [] || $taxLines->count() !== count(array_unique($taxLineIds))) {
                return PostingResult::refused(PostingResult::REFUSAL_TAX_NOT_ON_LINE);
            }

            if ($taxLines->contains(fn (FolioLine $taxLine): bool => $taxLine->reversal()->exists())) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            $batch = ($this->createManualBatch)($actor, $folio, $reason, $key);
            $date = $businessDate->toDateString();
            $reversals = [];

            foreach ($taxLines as $taxLine) {
                $reversals = [...$reversals, ...$this->reverseLine->post($actor, $taxLine, $date, null, $batch->id)];
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_tax_removed')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'manual_batch_id' => $batch->id,
                    'reason' => $batch->reason,
                    'tax_line_ids' => $taxLines->pluck('id')->all(),
                    'posting_date' => $date,
                ])
                ->log('Folio line tax removed');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($reversals);
        });
    }
}
