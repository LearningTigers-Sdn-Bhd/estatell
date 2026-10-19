<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioData;
use App\Data\Folio\FolioRowData;
use App\Data\Folio\ManualLineData;
use App\Data\Folio\ManualLineTaxData;
use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Models\User;
use Brick\Math\BigDecimal;

/**
 * The manual items of the row menu, on the manual folio page and on the folio tab. A live charge without a live
 * discount offers Split, Remove tax (when it has a tax), Add tax and Recalculate tax. On the manual page the other row
 * actions of the folio tab (Move, Refund, Remove discount, Correct price) are not wired, so its rows keep only Reverse
 * (for a user with `folio.reverse`); on the folio tab (`$keepActions`) every row keeps its actions. It changes the
 * rows' `actions` and returns what the Dialogs need, by charge line id. It writes nothing. See
 * docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
class ManualLineMenu
{
    /**
     * @return array<int, ManualLineData>
     */
    public function apply(User $user, FolioData $folio, int $propertyId, bool $keepActions = false): array
    {
        $charges = array_values(array_filter($folio->rows, fn (FolioRowData $row): bool => $row->state === 'posted' && $row->level === 0 && $row->kind === TransactionKind::Charge && $row->lineId !== null));
        $ids = array_map(fn (FolioRowData $row): int => (int) $row->lineId, $charges);
        $found = $ids === [] ? collect() : FolioLine::query()
            ->where(fn ($query) => $query->whereIn('id', $ids)->orWhereIn('parent_line_id', $ids))
            ->withExists('reversal')
            ->orderBy('id')
            ->get();
        $lines = $found->whereIn('id', $ids)->whereNull('reversal_of_id')->where('reversal_exists', false)->keyBy('id');
        $children = $found->whereIn('parent_line_id', $ids)->where('reversal_exists', false)->groupBy('parent_line_id');
        $taxesByCode = $children->flatten()->contains('kind', TransactionKind::Tax)
            ? Tax::query()->where('property_id', $propertyId)->get()->keyBy('transaction_code_id')
            : collect();
        $data = [];

        $canReverse = $user->can(PermissionEnum::FolioReverse->value);

        foreach ($folio->rows as $row) {
            $row->actions = $keepActions ? $row->actions : [];
        }

        foreach ($charges as $row) {
            $line = $lines->get($row->lineId);

            if ($line === null) {
                continue;
            }

            $lineChildren = $children->get($line->id, collect());
            $row->actions = $keepActions ? $row->actions : ($canReverse ? ['reverse'] : []);

            if ($lineChildren->contains('kind', TransactionKind::Adjustment)) {
                continue;
            }

            $taxes = array_values($lineChildren->where('kind', TransactionKind::Tax)->map(fn (FolioLine $tax): ManualLineTaxData => new ManualLineTaxData(
                $tax->id,
                $taxesByCode->get($tax->transaction_code_id)?->id,
                $tax->description,
                $tax->amount,
            ))->all());
            $data[$line->id] = new ManualLineData($line->id, (string) BigDecimal::of($line->unit_amount)->multipliedBy($line->quantity)->toScale(2), $taxes);
            $row->actions = [...$row->actions, 'split', ...($taxes === [] ? [] : ['remove_tax']), 'add_tax', 'recalculate_tax'];
        }

        return $data;
    }
}
