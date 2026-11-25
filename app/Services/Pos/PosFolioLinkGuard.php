<?php

namespace App\Services\Pos;

use App\Models\FolioLine;
use App\Models\PosOrderFolioLink;

/**
 * Tells the folio whether a line belongs to a restaurant POS order. Only the root charge line of a room charge is linked,
 * its tax children are not, so a child answers for its parent. Front desk cannot edit, split, discount, re-tax or reverse
 * a linked line (`pos_line_managed_in_pos`): the POS refund does it, so the receipt, shift and journal stay in step. Moving
 * the charge to another folio is allowed, and the link follows it (`follow()`). It reads only, apart from `follow()`.
 */
class PosFolioLinkGuard
{
    public function isManagedInPos(FolioLine $line): bool
    {
        return PosOrderFolioLink::query()->where('folio_line_id', $line->parent_line_id ?? $line->id)->exists();
    }

    /**
     * Point the POS link of a moved charge at its copy on the new folio. `TransferLines` calls it for each root line it moves.
     */
    public function follow(FolioLine $source, FolioLine $copy): void
    {
        PosOrderFolioLink::query()
            ->where('folio_line_id', $source->id)
            ->update(['folio_line_id' => $copy->id, 'folio_id' => $copy->folio_id, 'updated_at' => now()]);
    }
}
