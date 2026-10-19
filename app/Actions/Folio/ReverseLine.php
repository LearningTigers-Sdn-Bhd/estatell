<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ReverseLine
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private CloseSettledUnitFolio $closeSettledUnitFolio,
    ) {}

    /**
     * Post a line with the opposite amount and `reversal_of_id`, then a reversal for each descendant line, at every
     * level (a discount line has its own tax lines). A reversal of a child sits under the reversal of its parent. A
     * descendant that already has a reversal is skipped (the key `reverse:{line_id}` is unique). The folio row is locked first, so two
     * reversals of one line never race. Every check runs before the first write, so a refusal writes and logs nothing.
     * A line that a payment or refund owns is refused: a refund corrects a payment. The last step closes the folio when
     * it is an Open Unit folio of a CheckedOut unit at 0 (`CloseSettledUnitFolio`).
     * A reversed add-on keeps its `folio_line_id`, and a reversal keeps the source of its line. See docs/13-folio/02-posting-charges.md.
     */
    public function __invoke(User $actor, FolioLine $line): PostingResult
    {
        return DB::transaction(function () use ($actor, $line): PostingResult {
            $current = FolioLine::query()->findOrFail($line->id);
            $folio = Folio::query()->lockForUpdate()->findOrFail($current->folio_id);
            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            if ($current->parent_line_id !== null) {
                return PostingResult::refused(PostingResult::REFUSAL_LINE_IS_CHILD);
            }

            if ($current->reversal()->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            if ($current->reversal_of_id !== null) {
                return PostingResult::refused(PostingResult::REFUSAL_LINE_IS_REVERSAL);
            }

            if ($current->payment()->exists()) {
                return PostingResult::refused(PostingResult::REFUSAL_PAYMENT_LINE);
            }

            if ($folio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $lines = $this->post($actor, $current, $businessDate->toDateString(), null);

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.line_reversed')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_id' => $current->id,
                    'reversal_line_id' => $lines[0]->id,
                    'amount' => $lines[0]->amount,
                    'posting_date' => $businessDate->toDateString(),
                ])
                ->log('Folio line reversed');

            ($this->closeSettledUnitFolio)($actor, $folio);

            return PostingResult::posted($lines);
        });
    }

    /**
     * Write the reversal of a parent line and of its descendants, without any check. `TransferLines` and
     * `RemoveDiscount` call this after their own checks. Returns the reversal of the parent first, then the reversals
     * of its descendants, parents before children. A descendant that was reversed before is skipped, and its own
     * descendants are still reversed when they are not.
     *
     * @return non-empty-list<FolioLine>
     */
    public function post(User $actor, FolioLine $line, string $postingDate, ?string $transferGroupId): array
    {
        $reversal = $this->reversalOf($actor, $line, $postingDate, $transferGroupId, null);

        return [$reversal, ...$this->reverseChildren($actor, $line, $postingDate, $transferGroupId, $reversal->id)];
    }

    /**
     * @return list<FolioLine>
     */
    private function reverseChildren(User $actor, FolioLine $line, string $postingDate, ?string $transferGroupId, int $parentReversalId): array
    {
        $lines = [];

        foreach ($line->children()->orderBy('id')->get() as $child) {
            if ($child->reversal()->exists()) {
                $lines = [...$lines, ...$this->reverseChildren($actor, $child, $postingDate, $transferGroupId, $parentReversalId)];

                continue;
            }

            $reversal = $this->reversalOf($actor, $child, $postingDate, $transferGroupId, $parentReversalId);
            $lines = [...$lines, $reversal, ...$this->reverseChildren($actor, $child, $postingDate, $transferGroupId, $reversal->id)];
        }

        return $lines;
    }

    private function reversalOf(User $actor, FolioLine $line, string $postingDate, ?string $transferGroupId, ?int $parentReversalId): FolioLine
    {
        $reversal = new FolioLine([
            'transaction_code_id' => $line->transaction_code_id,
            'kind' => $line->kind,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount' => (string) BigDecimal::of($line->unit_amount)->negated()->toScale(2),
            'amount' => (string) BigDecimal::of($line->amount)->negated()->toScale(2),
            'source_type' => $line->source_type,
            'source_id' => $line->source_id,
        ]);
        $reversal->folio_id = $line->folio_id;
        $reversal->posting_date = $postingDate;
        $reversal->service_date = $line->service_date;
        $reversal->reversal_of_id = $line->id;
        $reversal->parent_line_id = $parentReversalId;
        $reversal->transfer_group_id = $transferGroupId;
        $reversal->idempotency_key = 'reverse:'.$line->id;
        $reversal->user_id = $actor->id;
        $reversal->save();

        return $reversal->refresh();
    }
}
