<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferLines
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ReverseLine $reverseLine,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Move whole charges to another folio of the same reservation: a reversal on the source (with all its descendants) and a
     * copy on the target (with its descendants that were not reversed). All lines of one transfer share one `transfer_group_id`. Both folio rows
     * are locked in id order, and every check runs before the first write, so a refusal writes and logs nothing.
     * A line that a payment or refund owns is refused. The result lists the copies on the target. The last step closes either folio when it is an Open Unit folio of a CheckedOut
     * unit at 0 (`CloseSettledUnitFolio`). See docs/13-folio/02-posting-charges.md.
     *
     * @param  list<FolioLine>  $lines
     */
    public function __invoke(User $actor, array $lines, Folio $target): PostingResult
    {
        return DB::transaction(function () use ($actor, $lines, $target): PostingResult {
            $ids = array_values(array_unique(array_map(fn (FolioLine $line): int => $line->id, $lines)));
            $sources = FolioLine::query()->whereIn('id', $ids)->orderBy('id')->get();
            $sourceFolioIds = $sources->pluck('folio_id')->unique()->all();

            if ($sources->count() !== count($ids) || $ids === [] || count($sourceFolioIds) !== 1 || $sourceFolioIds[0] === $target->id) {
                return PostingResult::refused(PostingResult::REFUSAL_TRANSFER_INVALID);
            }

            $folios = Folio::query()->whereIn('id', [$sourceFolioIds[0], $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sourceFolio = $folios[$sourceFolioIds[0]];
            $targetFolio = $folios[$target->id];
            $businessDate = $this->currentBusinessDate->date($sourceFolio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($sourceFolio->reservation_id !== $targetFolio->reservation_id) {
                return PostingResult::refused(PostingResult::REFUSAL_DIFFERENT_RESERVATION);
            }

            if ($sourceFolio->status !== FolioStatus::Open || $targetFolio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $reversedIds = FolioLine::query()->whereIn('reversal_of_id', $ids)->pluck('reversal_of_id')->all();

            foreach ($sources as $source) {
                if ($source->parent_line_id !== null || $source->reversal_of_id !== null || in_array($source->id, $reversedIds, true)) {
                    return PostingResult::refused(PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE);
                }
            }

            if (FolioLine::query()->whereIn('id', $ids)->whereHas('payment')->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_PAYMENT_LINE);
            }

            $group = (string) Str::uuid();
            $date = $businessDate->toDateString();
            $copies = [];

            foreach ($sources as $source) {
                $parentCopy = $this->copy($actor, $source, $targetFolio, $date, $group, null);
                $copies = [...$copies, $parentCopy, ...$this->copyChildren($actor, $source, $targetFolio, $date, $group, $parentCopy->id)];
                $this->reverseLine->post($actor, $source, $date, $group);
            }

            activity()
                ->performedOn($sourceFolio->reservation)
                ->causedBy($actor)
                ->event('folio.lines_transferred')
                ->withProperties([
                    'property_id' => $sourceFolio->property_id,
                    'from_folio_id' => $sourceFolio->id,
                    'to_folio_id' => $targetFolio->id,
                    'transfer_group_id' => $group,
                    'folio_line_ids' => $sources->pluck('id')->all(),
                    'posting_date' => $date,
                ])
                ->log('Folio lines transferred');

            ($this->closeSettledUnitFolio)($actor, $sourceFolio);
            ($this->closeSettledUnitFolio)($actor, $targetFolio);

            return PostingResult::posted($copies);
        });
    }

    /**
     * Copy every descendant that was not reversed (call it before the source is reversed), at every level, under the copy of its parent. A reversed line is
     * not copied (a removed discount stays removed), and its live descendants move under the nearest copied parent.
     *
     * @return list<FolioLine>
     */
    private function copyChildren(User $actor, FolioLine $line, Folio $target, string $postingDate, string $group, int $parentCopyId): array
    {
        $copies = [];

        foreach ($line->children()->orderBy('id')->get() as $child) {
            if ($child->reversal()->exists()) {
                $copies = [...$copies, ...$this->copyChildren($actor, $child, $target, $postingDate, $group, $parentCopyId)];

                continue;
            }

            $copy = $this->copy($actor, $child, $target, $postingDate, $group, $parentCopyId);
            $copies = [...$copies, $copy, ...$this->copyChildren($actor, $child, $target, $postingDate, $group, $copy->id)];
        }

        return $copies;
    }

    private function copy(User $actor, FolioLine $line, Folio $target, string $postingDate, string $group, ?int $parentCopyId): FolioLine
    {
        $copy = new FolioLine([
            'transaction_code_id' => $line->transaction_code_id,
            'kind' => $line->kind,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount' => $line->unit_amount,
            'amount' => $line->amount,
            'source_type' => $line->source_type,
            'source_id' => $line->source_id,
        ]);
        $copy->folio_id = $target->id;
        $copy->posting_date = $postingDate;
        $copy->parent_line_id = $parentCopyId;
        $copy->transfer_group_id = $group;
        $copy->idempotency_key = 'transfer:'.$group.':'.$line->id;
        $copy->user_id = $actor->id;
        $copy->save();

        return $copy->refresh();
    }
}
