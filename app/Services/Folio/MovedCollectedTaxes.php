<?php

namespace App\Services\Folio;

use App\Enums\TransactionKind;
use App\Models\FolioLine;
use Illuminate\Support\Collection;

/**
 * Finds the collected taxes that Move charges put back under their room line (`MoveCollectedTaxes`): a Tax copy keyed
 * `transfer:{group}:{original id}` whose original is a Tax child of the same room line on another folio. A child that
 * moved with its charge (`TransferLines`) has another parent than its original, so it never matches. It only reads.
 * See docs/13-folio/07-move-charges.md#undo-a-tax-move.
 */
class MovedCollectedTaxes
{
    /**
     * The original of each given line that is such a copy, by copy id, with its folio loaded. One query.
     *
     * @param  Collection<int, FolioLine>  $lines
     * @return array<int, FolioLine>
     */
    public function originals(Collection $lines): array
    {
        $copies = $lines->filter(fn (FolioLine $line): bool => $line->kind === TransactionKind::Tax
            && $line->parent_line_id !== null
            && $line->reversal_of_id === null
            && $line->transfer_group_id !== null
            && str_starts_with((string) $line->idempotency_key, 'transfer:'.$line->transfer_group_id.':'));

        if ($copies->isEmpty()) {
            return [];
        }

        $originalIds = $copies->mapWithKeys(fn (FolioLine $copy): array => [$copy->id => (int) substr((string) $copy->idempotency_key, strlen('transfer:'.$copy->transfer_group_id.':'))]);
        $originals = FolioLine::query()->whereIn('id', $originalIds->values()->all())->with('folio')->get()->keyBy('id');
        $found = [];

        foreach ($copies as $copy) {
            $original = $originals->get($originalIds[$copy->id]);

            if ($original !== null && $original->kind === TransactionKind::Tax && $original->parent_line_id === $copy->parent_line_id && $original->folio_id !== $copy->folio_id) {
                $found[$copy->id] = $original;
            }
        }

        return $found;
    }
}
