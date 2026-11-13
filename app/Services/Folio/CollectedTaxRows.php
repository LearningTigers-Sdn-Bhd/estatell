<?php

namespace App\Services\Folio;

use App\Data\Folio\CollectedTaxNoteData;
use App\Data\Folio\FolioOptionData;
use App\Data\Folio\FolioRowData;
use App\Enums\FolioRowTag;
use App\Enums\FolioType;
use App\Enums\PermissionEnum;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Explains taxes on a unit folio whose room charge sits on another folio (the collected taxes a guest pays while a
 * company pays the room, see `CollectedTaxPayer`). It reads the parent rows the folio tab shows above those taxes, and
 * the note above the table. It only reads. See docs/13-folio/05-folio-page.md#collected-taxes.
 */
class CollectedTaxRows
{
    /**
     * The parent lines of `$lines` that sit on another folio, by id, with their folio and its payer loaded.
     *
     * @param  Collection<int, FolioLine>  $lines  The lines of one folio.
     * @return Collection<int, FolioLine>
     */
    public function parentsElsewhere(Collection $lines): Collection
    {
        $ids = $lines->pluck('id')->flip();
        $parentIds = $lines
            ->filter(fn (FolioLine $line): bool => $line->parent_line_id !== null && ! $ids->has($line->parent_line_id))
            ->pluck('parent_line_id')
            ->unique()
            ->values();

        if ($parentIds->isEmpty()) {
            return collect();
        }

        return FolioLine::query()
            ->whereIn('id', $parentIds)
            ->with('folio.payerCompany:id,name', 'folio.payerGuest')
            ->get()
            ->keyBy('id');
    }

    /**
     * The muted row of a parent charge on `$folio`, shown above its taxes. Its amount is 0.00 so no total counts it.
     */
    public function parentRow(string $key, string $state, string $date, string $description, ?string $unit, ?TransactionKind $kind, Folio $folio): FolioRowData
    {
        return new FolioRowData($key, $state, $date, $description, 0, $unit, '0.00', [], kind: $kind, onFolio: new FolioOptionData($folio->id, $this->folioName($folio)));
    }

    /**
     * The note for a folio whose rows hold parent rows: who pays the room, and the taxes the guest pays instead. Null
     * when no parent row names a folio a company pays.
     *
     * @param  list<FolioRowData>  $rows
     * @param  Collection<int, Folio>  $folios  The reservation's folios by id, with `payerCompany` loaded.
     */
    public function note(array $rows, Collection $folios, ?User $user): ?CollectedTaxNoteData
    {
        $company = null;
        $companyFolio = null;
        $taxes = [];
        $under = null;
        $moveLineIds = [];

        foreach ($rows as $row) {
            if ($row->onFolio !== null) {
                $payer = $folios->get($row->onFolio->id)?->payerCompany;

                if ($company === null && $payer !== null) {
                    $company = $payer;
                    $companyFolio = $row->onFolio;
                }

                $under = $row->onFolio->id;

                continue;
            }

            if ($row->level === 0) {
                $under = null;
            } elseif ($under !== null && $row->kind === TransactionKind::Tax && ! $this->isCorrection($row)) {
                $taxes[$row->description] = true;

                if ($under === $companyFolio?->id && $row->lineId !== null && in_array('move', $row->actions, true)) {
                    $moveLineIds[] = $row->lineId;
                }
            }
        }

        if ($company === null || $taxes === []) {
            return null;
        }

        return new CollectedTaxNoteData(
            $company->name,
            array_keys($taxes),
            $user?->can(PermissionEnum::CompanyManage->value) ? $company->id : null,
            $moveLineIds,
            $moveLineIds === [] ? null : $companyFolio?->id,
            $moveLineIds === [] ? null : $companyFolio?->label,
        );
    }

    private function isCorrection(FolioRowData $row): bool
    {
        return collect($row->tags)->contains(fn ($tag): bool => in_array($tag->value, [FolioRowTag::Reversal->value, FolioRowTag::Reversed->value], true));
    }

    /**
     * "Master", or the payer of a third-party folio ("Blue Reef Dive"), as the link under a parent row names it.
     */
    private function folioName(Folio $folio): string
    {
        return match ($folio->type) {
            FolioType::Master => __('Master'),
            FolioType::ThirdParty => $folio->payerCompany->name ?? $folio->payerGuest->full_name ?? __('Third party'),
            FolioType::Unit => __('Unit folio'),
        };
    }
}
