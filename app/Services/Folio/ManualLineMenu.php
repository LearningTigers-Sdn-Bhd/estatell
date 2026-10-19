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
 * The row menu of the Posted lines tab on the manual folio page. A live charge offers Reverse to a user with
 * `folio.reverse`, and, when it has no live discount, Split, Remove tax (when it has a tax), Add tax and Recalculate
 * tax. The other row actions of the folio tab (Move, Refund, Remove discount, Correct price) are not wired on this
 * page, so its rows get none of them. It changes the rows' `actions` and returns what the Dialogs need, by charge
 * line id. It writes nothing. See docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
class ManualLineMenu
{
    /**
     * @return array<int, ManualLineData>
     */
    public function apply(User $user, FolioData $folio, int $propertyId): array
    {
        $charges = array_values(array_filter($folio->rows, fn (FolioRowData $row): bool => $row->state === 'posted' && $row->level === 0 && $row->kind === TransactionKind::Charge && $row->lineId !== null));
        $lines = FolioLine::query()
            ->whereIn('id', array_map(fn (FolioRowData $row): int => (int) $row->lineId, $charges))
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->with(['children' => fn ($query) => $query->whereDoesntHave('reversal')->orderBy('id')])
            ->get()
            ->keyBy('id');
        $taxesByCode = Tax::query()->where('property_id', $propertyId)->get()->keyBy('transaction_code_id');
        $data = [];

        $canReverse = $user->can(PermissionEnum::FolioReverse->value);

        foreach ($folio->rows as $row) {
            $row->actions = [];
        }

        foreach ($charges as $row) {
            $line = $lines->get($row->lineId);

            if ($line === null) {
                continue;
            }

            $row->actions = $canReverse ? ['reverse'] : [];

            if ($line->children->contains('kind', TransactionKind::Adjustment)) {
                continue;
            }

            $taxes = array_values($line->children->where('kind', TransactionKind::Tax)->map(fn (FolioLine $tax): ManualLineTaxData => new ManualLineTaxData(
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
