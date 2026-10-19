<?php

namespace App\Actions\Folio;

use App\Data\Folio\SplitPartData;
use App\Enums\FolioStatus;
use App\Enums\TaxRateType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use App\Services\Folio\LineTaxes;
use App\Services\Folio\ManualBatches;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SplitLine
{
    public const int MAX_PARTS = 20;

    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private LineTaxes $lineTaxes,
        private ManualBatches $manualBatches,
        private CreateManualBatch $createManualBatch,
        private ReverseLine $reverseLine,
        private PostFolioCharge $postFolioCharge,
        private TaxCalculator $taxCalculator,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Split one charge into 2 to 20 parts, each on a folio of the same reservation: a reversal of the charge and its
     * tax lines, then one new charge for each part with the same item, description, source and service date and one
     * `transfer_group_id`. The parts must add up to the entered amount of the line (unit amount times quantity). The tax
     * of each part is calculated again on its own amount with the taxes the charge has now, so each part carries a
     * tax that fits it. A flat tax goes to the first part only, because it is charged once for the line; the first
     * part is also charged it for the whole quantity. Folios are locked in id order and every check runs before the
     * first write, so a refusal writes and logs nothing. A charge with a discount is refused (`line_has_discount`).
     * The batch key is the form's UUID: a repeat returns the batch and writes nothing. A line that is a child, a
     * reversal or already reversed is `line_not_transferable`. Permissions are checked by the route. See
     * docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
     *
     * @param  list<SplitPartData>  $parts
     */
    public function __invoke(User $actor, FolioLine $line, array $parts, string $reason, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $line, $parts, $reason, $key): PostingResult {
            $source = FolioLine::query()->findOrFail($line->id);
            $ids = collect([$source->folio_id, ...array_map(fn (SplitPartData $part): int => $part->folioId, $parts)])->unique()->sort()->values();
            $folios = Folio::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $folio = $folios->get($source->folio_id);

            if (($replayed = $this->manualBatches->replay($folio, $key)) !== null) {
                return $replayed;
            }

            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $refusal = $this->lineTaxes->chargeRefusal($source, $folio);

            if ($refusal !== null) {
                return PostingResult::refused(in_array($refusal, [PostingResult::REFUSAL_LINE_IS_CHILD, PostingResult::REFUSAL_LINE_IS_REVERSAL, PostingResult::REFUSAL_ALREADY_REVERSED], true) ? PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE : $refusal);
            }

            if (count($parts) < 2 || count($parts) > self::MAX_PARTS || $folios->count() !== $ids->count()
                || collect($parts)->contains(fn (SplitPartData $part): bool => preg_match('/^\d+(\.\d{1,2})?$/', $part->amount) !== 1 || (float) $part->amount <= 0)) {
                return PostingResult::refused(PostingResult::REFUSAL_SPLIT_INVALID);
            }

            if ($folios->contains(fn (Folio $other): bool => $other->reservation_id !== $folio->reservation_id)) {
                return PostingResult::refused(PostingResult::REFUSAL_DIFFERENT_RESERVATION);
            }

            if ($folios->contains(fn (Folio $other): bool => $other->status !== FolioStatus::Open)) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $currency = $folio->property->settings->currency->getCurrencyCode();
            $total = collect($parts)->reduce(fn (Money $sum, SplitPartData $part): Money => $sum->plus($part->amount), Money::zero($currency));

            if (! $total->isEqualTo($this->lineTaxes->enteredAmount($source, $currency))) {
                return PostingResult::refused(PostingResult::REFUSAL_SPLIT_TOTAL_MISMATCH);
            }

            $taxes = $this->lineTaxes->liveTaxLines($source)
                ->map(fn (FolioLine $taxLine): ?Tax => $this->lineTaxes->taxOf($taxLine, $folio->property_id))
                ->filter()
                ->values();
            $nationality = $this->lineTaxes->nationality($source);
            $calculations = [];

            foreach ($parts as $index => $part) {
                $partTaxes = $index === 0 ? $taxes : $taxes->reject(fn (Tax $tax): bool => $tax->rate_type === TaxRateType::Flat)->values();
                $calculation = $this->taxCalculator->calculateWithTaxes($folio->property, $partTaxes, Money::of($part->amount, $currency), $index === 0 ? (string) $source->quantity : '1', $nationality);

                if (! $calculation->isCalculated()) {
                    return PostingResult::refused((string) $calculation->refusalReason);
                }

                $calculations[$index] = $calculation;
            }

            $batch = ($this->createManualBatch)($actor, $folio, $reason, $key);
            $group = (string) Str::uuid();
            $date = $businessDate->toDateString();
            $this->reverseLine->post($actor, $source, $date, $group, $batch->id);
            $written = [];

            foreach ($parts as $index => $part) {
                $written = [...$written, ...$this->postPart($actor, $source, $folios->get($part->folioId), $part, $calculations[$index], $index, $date, $group, $batch->id, $currency)];
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_split')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $source->id,
                    'manual_batch_id' => $batch->id,
                    'reason' => $batch->reason,
                    'parts' => count($parts),
                    'folio_ids' => array_map(fn (SplitPartData $part): int => $part->folioId, $parts),
                    'posting_date' => $date,
                ])
                ->log('Folio line split');

            foreach ($folios as $other) {
                ($this->closeSettledUnitFolio)($actor, $other);
            }

            return PostingResult::posted($written);
        });
    }

    /**
     * @return non-empty-list<FolioLine>
     */
    private function postPart(User $actor, FolioLine $source, Folio $target, SplitPartData $part, TaxCalculationResult $tax, int $index, string $date, string $group, int $batchId, string $currency): array
    {
        return ($this->postFolioCharge)(
            $actor,
            $target,
            $source->transactionCode,
            $source->description,
            1,
            Money::of($part->amount, $currency),
            $tax,
            'split:'.$source->id.':'.$index,
            $date,
            $source->source,
            serviceDate: $source->service_date->toDateString(),
            manualBatchId: $batchId,
            transferGroupId: $group,
        );
    }
}
