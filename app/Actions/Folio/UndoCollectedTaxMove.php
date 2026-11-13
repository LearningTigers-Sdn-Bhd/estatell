<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use App\Services\Folio\MovedCollectedTaxes;
use App\Services\NightAudit\CurrentBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UndoCollectedTaxMove
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private ReverseLine $reverseLine,
        private TransferLines $transferLines,
        private MovedCollectedTaxes $movedCollectedTaxes,
    ) {}

    /**
     * Send back the collected taxes of one Move charges move, from any of them: every live tax that move put under its
     * room line on this folio gets a reversal, and its original is posted again on the folio it came from, under the same
     * room line, all in a new transfer group. Charges moved in the same move stay, and so does a tax of the move that was
     * reversed since (with its room line). A line that is no such tax is `not_a_tax_move`; one already reversed or undone
     * is `already_reversed`; every folio must be Open (`folio_not_open`). The
     * folios are locked in id order and every check runs before the first write, so a refusal writes and logs nothing.
     * Permissions are checked by the route. See docs/13-folio/07-move-charges.md#undo-a-tax-move.
     */
    public function __invoke(User $actor, FolioLine $line): PostingResult
    {
        return DB::transaction(function () use ($actor, $line): PostingResult {
            $current = FolioLine::query()->findOrFail($line->id);

            $group = FolioLine::query()
                ->where('transfer_group_id', $current->transfer_group_id)
                ->where('folio_id', $current->folio_id)
                ->whereNull('reversal_of_id')
                ->orderBy('id')
                ->get();
            $originals = $this->movedCollectedTaxes->originals($group);

            if ($current->transfer_group_id === null || ! isset($originals[$current->id])) {
                return PostingResult::refused(PostingResult::REFUSAL_NOT_A_TAX_MOVE);
            }

            $reversed = FolioLine::query()->whereIn('reversal_of_id', array_keys($originals))->pluck('reversal_of_id')->all();

            if (in_array($current->id, $reversed, true)) {
                return PostingResult::refused(PostingResult::REFUSAL_ALREADY_REVERSED);
            }

            $copies = $group->filter(fn (FolioLine $copy): bool => isset($originals[$copy->id]) && ! in_array($copy->id, $reversed, true))->values();
            $originals = array_intersect_key($originals, array_flip($copies->modelKeys()));

            $folios = Folio::query()
                ->whereIn('id', [$current->folio_id, ...array_map(fn (FolioLine $original): int => $original->folio_id, $originals)])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($folios->contains(fn (Folio $folio): bool => $folio->status !== FolioStatus::Open)) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }

            $folio = $folios->firstWhere('id', $current->folio_id);
            $businessDate = $this->currentBusinessDate->date($folio->property);

            if ($businessDate === null) {
                return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $date = $businessDate->toDateString();
            $undo = (string) Str::uuid();
            $lines = [];

            foreach ($copies as $copy) {
                $original = $originals[$copy->id];
                $lines = [...$lines, ...$this->reverseLine->post($actor, $copy, $date, $undo)];
                $lines[] = $this->transferLines->copy($actor, $original, $original->folio, $date, $undo, $original->parent_line_id);
            }

            activity()
                ->performedOn($folio->reservation)
                ->causedBy($actor)
                ->event('folio.collected_tax_move_undone')
                ->withProperties([
                    'property_id' => $folio->property_id,
                    'folio_id' => $folio->id,
                    'folio_line_ids' => $copies->modelKeys(),
                    'transfer_group_id' => $undo,
                    'count' => $copies->count(),
                    'amount' => (string) $copies->reduce(fn (BigDecimal $sum, FolioLine $copy): BigDecimal => $sum->plus($copy->amount), BigDecimal::zero())->toScale(2),
                ])
                ->log(__('Moved :count :taxes back to the guest folio.', ['count' => $copies->count(), 'taxes' => $copies->count() === 1 ? __('tax') : __('taxes')]));

            return PostingResult::posted($lines);
        });
    }
}
