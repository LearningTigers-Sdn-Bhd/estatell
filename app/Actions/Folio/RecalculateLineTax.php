<?php

namespace App\Actions\Folio;

use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\LineTaxes;
use App\Services\Folio\ManualBatches;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class RecalculateLineTax
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private LineTaxes $lineTaxes,
        private ManualBatches $manualBatches,
        private CreateManualBatch $createManualBatch,
        private ReverseLine $reverseLine,
        private PostLineTax $postLineTax,
        private TaxCalculator $taxCalculator,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Reverse the live tax lines of a posted charge and post new ones that `TaxCalculator` works out now, with the
     * taxes of the charge's item and the nationality of the line's unit today. The charge line does not change, so
     * when the new taxes would change its net amount the Action refuses (`net_amount_changed`); when the new tax
     * lines equal the live ones it refuses (`tax_unchanged`). A charge with a discount is refused
     * (`line_has_discount`). The key of a new tax line is `tax-recalc:{line_id}:{n}:tax:{tax_id}`, where n counts the
     * earlier recalculations. The batch key is the form's UUID: a repeat returns the batch and writes nothing. Every
     * check runs before the first write. Permissions are checked by the route. See
     * docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
     */
    public function __invoke(User $actor, FolioLine $charge, string $reason, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $charge, $reason, $key): PostingResult {
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

            $currency = $folio->property->settings->currency->getCurrencyCode();
            $calculation = $this->taxCalculator->calculate($folio->property, $current->transactionCode, $this->lineTaxes->enteredAmount($current, $currency), (string) $current->quantity, $this->lineTaxes->nationality($current));

            if (! $calculation->isCalculated()) {
                return PostingResult::refused((string) $calculation->refusalReason);
            }

            if (! $calculation->netAmount->isEqualTo($current->amount)) {
                return PostingResult::refused(PostingResult::REFUSAL_NET_AMOUNT_CHANGED);
            }

            $live = $this->lineTaxes->liveTaxLines($current);
            $before = $live->mapWithKeys(fn (FolioLine $taxLine): array => [$taxLine->transaction_code_id => Money::of($taxLine->amount, $currency)]);
            $after = collect($calculation->taxes)->mapWithKeys(fn ($component): array => [$component->transactionCodeId => $component->amount]);

            if ($before->count() === $after->count() && $after->every(fn (Money $amount, int $codeId): bool => $before->has($codeId) && $before->get($codeId)->isEqualTo($amount))) {
                return PostingResult::refused(PostingResult::REFUSAL_TAX_UNCHANGED);
            }

            $batch = ($this->createManualBatch)($actor, $folio, $reason, $key);
            $date = $businessDate->toDateString();
            $lines = [];

            foreach ($live as $taxLine) {
                $lines = [...$lines, ...$this->reverseLine->post($actor, $taxLine, $date, null, $batch->id)];
            }

            $run = 0;

            while (FolioLine::query()->where('idempotency_key', 'like', 'tax-recalc:'.$current->id.':'.$run.':%')->exists()) {
                $run++;
            }

            foreach ($calculation->taxes as $component) {
                $lines[] = ($this->postLineTax)($actor, $current, $component, 'tax-recalc:'.$current->id.':'.$run.':tax:'.$component->taxId, $date, $batch->id);
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_tax_recalculated')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'manual_batch_id' => $batch->id,
                    'reason' => $batch->reason,
                    'posting_date' => $date,
                ])
                ->log('Folio line tax recalculated');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }
}
