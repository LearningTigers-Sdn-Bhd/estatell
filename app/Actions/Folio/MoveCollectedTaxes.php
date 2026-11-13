<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;

class MoveCollectedTaxes
{
    public function __construct(private TransferLines $transferLines, private ReverseLine $reverseLine) {}

    /**
     * Put collected taxes the guest was to pay (see `CollectedTaxPayer`) back under their room line on the target: a
     * reversal on the folio each sits on, and a copy on the target as a child of the same room line, both in
     * `$group`. Each line must be a live Tax child whose live room line sits on the target while the tax does not
     * (`line_not_transferable`), and its folio must be Open (`folio_not_open`). Every check runs before the first write,
     * so a refusal writes nothing. It runs inside the caller's transaction (`MoveCharges`), which has locked the folios
     * and logs the move. See docs/13-folio/07-move-charges.md#collected-taxes.
     *
     * @param  list<FolioLine>  $lines
     */
    public function __invoke(User $actor, array $lines, Folio $target, string $postingDate, string $group): PostingResult
    {
        $taxes = FolioLine::query()->whereIn('id', array_map(fn (FolioLine $line): int => $line->id, $lines))->with('parent', 'folio')->orderBy('id')->get();
        $reversed = FolioLine::query()
            ->whereIn('reversal_of_id', [...$taxes->modelKeys(), ...$taxes->pluck('parent_line_id')->filter()->all()])
            ->pluck('reversal_of_id')
            ->all();

        foreach ($taxes as $tax) {
            $parent = $tax->parent;

            if ($tax->kind !== TransactionKind::Tax
                || $tax->reversal_of_id !== null
                || in_array($tax->id, $reversed, true)
                || $parent === null
                || $parent->parent_line_id !== null
                || in_array($parent->id, $reversed, true)
                || $parent->folio_id !== $target->id
                || $tax->folio_id === $target->id) {
                return PostingResult::refused(PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE);
            }

            if ($tax->folio->status !== FolioStatus::Open) {
                return PostingResult::refused(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
            }
        }

        $copies = [];

        foreach ($taxes as $tax) {
            $copies[] = $this->transferLines->copy($actor, $tax, $target, $postingDate, $group, $tax->parent_line_id);
            $this->reverseLine->post($actor, $tax, $postingDate, $group);
        }

        return PostingResult::posted($copies);
    }
}
