<?php

namespace App\Actions\Folio;

use App\Data\Folio\EditLineData;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Folio\EditLinePreview;
use App\Services\Folio\ManualBatches;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;

class EditLine
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private EditLinePreview $editLinePreview,
        private ManualBatches $manualBatches,
        private CreateManualBatch $createManualBatch,
        private ReverseLine $reverseLine,
        private PostFolioCharge $postFolioCharge,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Change a posted charge's description, unit amount, quantity or taxes: a reversal of the charge and its tax
     * lines, then one new charge with the same item, source, service date and `migrated` flag and the new values, on
     * the business date. The tax is calculated again with the taxes given (the whole list). `EditLinePreview::plan()`
     * runs every check before the first write, so a refusal writes and logs nothing: a charge with a discount
     * (`line_has_discount`), a room night's own line (`night_line`, which changes through Change price), nothing
     * different (`nothing_changed`) and the posting refusals. The new line's key is `edit:{line_id}`. The batch key is
     * the form's UUID: a repeat returns the batch and writes nothing. Permissions are checked by the route. See
     * docs/13-folio/08-manual-folio.md#edit-line.
     */
    public function __invoke(User $actor, FolioLine $line, EditLineData $edit, string $reason, string $key): PostingResult
    {
        return DB::transaction(function () use ($actor, $line, $edit, $reason, $key): PostingResult {
            $source = FolioLine::query()->findOrFail($line->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($source->folio_id);

            if (($replayed = $this->manualBatches->replay($folio, $key)) !== null) {
                return $replayed;
            }

            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $plan = $this->editLinePreview->plan($source, $folio, $edit);

            if ($plan->refusal !== null) {
                return PostingResult::refused($plan->refusal);
            }

            $batch = ($this->createManualBatch)($actor, $folio, $reason, $key);
            $date = $businessDate->toDateString();
            $this->reverseLine->post($actor, $source, $date, null, $batch->id);
            $written = ($this->postFolioCharge)(
                $actor,
                $folio,
                $source->transactionCode,
                trim($edit->description),
                $edit->quantity,
                $plan->unitAmount,
                $plan->tax,
                'edit:'.$source->id,
                $date,
                $source->source,
                serviceDate: $source->service_date->toDateString(),
                manualBatchId: $batch->id,
                migrated: $source->migrated,
            );

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_edited')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $source->id,
                    'new_line_id' => $written[0]->id,
                    'manual_batch_id' => $batch->id,
                    'reason' => $batch->reason,
                    'old' => ['description' => $source->description, 'unit_amount' => $source->unit_amount, 'quantity' => $source->quantity],
                    'new' => ['description' => trim($edit->description), 'unit_amount' => $edit->amount, 'quantity' => $edit->quantity, 'tax_ids' => $edit->taxIds],
                    'posting_date' => $date,
                ])
                ->log('Folio line edited');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($written);
        });
    }
}
