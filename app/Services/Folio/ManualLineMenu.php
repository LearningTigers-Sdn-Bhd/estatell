<?php

namespace App\Services\Folio;

use App\Data\Folio\FolioData;
use App\Data\Folio\FolioRowData;
use App\Data\Folio\ManualLineData;
use App\Data\Folio\ManualLineNightData;
use App\Data\Folio\ManualLineTaxData;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Models\UnitNight;
use App\Models\User;
use Brick\Math\BigDecimal;
use Closure;

/**
 * The manual items of the row menu, on the manual folio page and on the folio tab. A live charge without a live
 * discount offers Edit line, Split, Remove tax (when it has a tax), Add tax and Recalculate tax. A live room night with a
 * discount offers Edit line only, because its price goes through Change price, which replaces the discount. On the manual page the other row
 * actions of the folio tab (Move, Refund, Remove discount, Correct price) are not wired, so its rows keep only Reverse
 * (for a user with `folio.reverse`); on the folio tab (`$keepActions`) every row keeps its actions. With
 * `$changeOpeningBalance` (the tab: a check that the migration window is open, asked only when the folio has an
 * Adjustment row) the live Opening balance line also gets Change opening balance. It changes the
 * rows' `actions` and returns what the Dialogs need, by charge line id. It writes nothing. See
 * docs/13-folio/08-manual-folio.md#row-menu-on-posted-lines.
 */
class ManualLineMenu
{
    public function __construct(private EditLinePreview $editLinePreview) {}

    /**
     * @return array<int, ManualLineData>
     */
    public function apply(User $user, FolioData $folio, int $propertyId, bool $keepActions = false, ?Closure $changeOpeningBalance = null): array
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
        $canChangePrice = $user->can(PermissionEnum::ReservationsChangePrice->value);
        $nightIds = array_values(array_filter(array_map(fn (FolioLine $line): ?int => $this->editLinePreview->nightId($line), $lines->all())));
        $nights = $nightIds === [] ? collect() : UnitNight::query()->whereIn('id', $nightIds)->get()->keyBy('id');

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

            $discounted = $lineChildren->contains('kind', TransactionKind::Adjustment);
            $night = $nights->get($this->editLinePreview->nightId($line));

            if ($discounted && $night === null) {
                continue;
            }

            $taxes = array_values($lineChildren->where('kind', TransactionKind::Tax)->map(fn (FolioLine $tax): ManualLineTaxData => new ManualLineTaxData(
                $tax->id,
                $taxesByCode->get($tax->transaction_code_id)?->id,
                $tax->description,
                $tax->amount,
            ))->all());
            $isInclusion = $night === null && str_starts_with((string) $line->idempotency_key, 'night:');
            $data[$line->id] = new ManualLineData(
                $line->id,
                (string) BigDecimal::of($line->unit_amount)->multipliedBy($line->quantity)->toScale(2),
                $taxes,
                $line->description,
                (string) BigDecimal::of($line->unit_amount)->toScale(2),
                (int) $line->quantity,
                array_values(array_filter(array_map(fn (ManualLineTaxData $tax): ?int => $tax->taxId, $taxes))),
                $night === null ? null : new ManualLineNightData($night->reservation_unit_id, $night->id, (string) $night->amount, $canChangePrice, $discounted),
            );
            $row->actions = $discounted ? ['edit', ...$row->actions] : [...($isInclusion ? [] : ['edit']), ...$row->actions, 'split', ...($taxes === [] ? [] : ['remove_tax']), 'add_tax', 'recalculate_tax'];
        }

        if ($keepActions && $changeOpeningBalance !== null) {
            $data += $this->openingBalanceLines($folio, $changeOpeningBalance);
        }

        return $data;
    }

    /**
     * The live Opening balance line of the folio, with the action added to its row.
     *
     * @return array<int, ManualLineData>
     */
    private function openingBalanceLines(FolioData $folio, Closure $windowOpen): array
    {
        $rows = array_values(array_filter($folio->rows, fn (FolioRowData $row): bool => $row->state === 'posted' && $row->level === 0 && $row->kind === TransactionKind::Adjustment && $row->lineId !== null));

        if ($rows === [] || ! $windowOpen()) {
            return [];
        }

        $lines = FolioLine::query()
            ->whereIn('id', array_map(fn (FolioRowData $row): int => (int) $row->lineId, $rows))
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->whereHas('transactionCode', fn ($codes) => $codes->where('system_key', SystemTransactionCode::OpeningBalance->value))
            ->get()
            ->keyBy('id');
        $data = [];

        foreach ($rows as $row) {
            $line = $lines->get($row->lineId);

            if ($line === null) {
                continue;
            }

            $row->actions = [...$row->actions, 'change_opening_balance'];
            $data[$line->id] = new ManualLineData($line->id, (string) BigDecimal::of($line->amount)->toScale(2), []);
        }

        return $data;
    }
}
