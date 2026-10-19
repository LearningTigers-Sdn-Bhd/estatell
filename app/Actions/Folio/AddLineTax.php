<?php

namespace App\Actions\Folio;

use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\LineTaxes;
use App\Services\Folio\ManualBatches;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class AddLineTax
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private LineTaxes $lineTaxes,
        private ManualBatches $manualBatches,
        private CreateManualBatch $createManualBatch,
        private PostLineTax $postLineTax,
        private TaxCalculator $taxCalculator,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Add the chosen taxes to a posted charge as new Tax lines under it. `TaxCalculator` works out each amount on the
     * net amount of the charge (and its quantity, for a flat tax) with the nationality of the line's unit. The charge
     * line does not change. A tax that is not an enabled tax of the property is `tax_not_available`, one the charge
     * already has is `tax_already_on_line`, and one that is part of the price is `tax_inclusive`, because adding it
     * would change the net amount. A charge with a discount is refused (`line_has_discount`). The key of a tax line is
     * `tax-add:{line_id}:{tax_id}:{n}`, where n counts the earlier ones. The batch key is the form's UUID: a repeat
     * returns the batch and writes nothing. Every check runs before the first write. Permissions are checked by the
     * route. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
     *
     * @param  list<int>  $taxIds
     */
    public function __invoke(User $actor, FolioLine $charge, array $taxIds, string $reason, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $charge, $taxIds, $reason, $key): PostingResult {
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

            $ids = array_values(array_unique($taxIds));
            $chosen = $folio->property->taxes()->whereKey($ids)->get();

            if ($ids === [] || $chosen->count() !== count($ids) || $chosen->contains(fn (Tax $tax): bool => ! $tax->enabled)) {
                return PostingResult::refused(PostingResult::REFUSAL_TAX_NOT_AVAILABLE);
            }

            $liveCodes = $this->lineTaxes->liveTaxLines($current)->pluck('transaction_code_id')->all();

            if ($chosen->contains(fn (Tax $tax): bool => in_array($tax->transaction_code_id, $liveCodes, true))) {
                return PostingResult::refused(PostingResult::REFUSAL_TAX_ALREADY_ON_LINE);
            }

            if ($chosen->contains(fn (Tax $tax): bool => $tax->is_inclusive)) {
                return PostingResult::refused(PostingResult::REFUSAL_TAX_INCLUSIVE);
            }

            $currency = $folio->property->settings->currency->getCurrencyCode();
            $calculation = $this->taxCalculator->calculateWithTaxes($folio->property, $chosen, Money::of($current->amount, $currency), (string) $current->quantity, $this->lineTaxes->nationality($current));

            if (! $calculation->isCalculated()) {
                return PostingResult::refused((string) $calculation->refusalReason);
            }

            $batch = ($this->createManualBatch)($actor, $folio, $reason, $key);
            $date = $businessDate->toDateString();
            $lines = [];

            foreach ($calculation->taxes as $component) {
                $earlier = FolioLine::query()->where('idempotency_key', 'like', 'tax-add:'.$current->id.':'.$component->taxId.':%')->count();
                $lines[] = ($this->postLineTax)($actor, $current, $component, 'tax-add:'.$current->id.':'.$component->taxId.':'.$earlier, $date, $batch->id);
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_tax_added')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'manual_batch_id' => $batch->id,
                    'reason' => $batch->reason,
                    'tax_ids' => $ids,
                    'posting_date' => $date,
                ])
                ->log('Folio line tax added');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
