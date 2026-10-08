<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioBalance;

class CloseSettledUnitFolio
{
    public function __construct(
        private FolioBalance $folioBalance,
        private CloseFolio $closeFolio,
    ) {}

    /**
     * Close an Open Unit folio of a CheckedOut unit when it is at 0. The posting, payment, refund, reversal and
     * transfer Actions call it as their last step, inside their transaction, so staff who reopened a folio to fix
     * something never have to close it again. A folio above or below 0, a folio of a unit that is not CheckedOut, and
     * any other folio are left alone. A refusal of `CloseFolio` is ignored: the folio just stays Open. See
     * docs/13-folio/01-folios-and-lines.md#close-and-reopen.
     */
    public function __invoke(User $actor, Folio $folio): void
    {
        $current = Folio::query()->find($folio->id);

        if ($current === null || $current->status !== FolioStatus::Open || $current->type !== FolioType::Unit || $current->reservation_unit_id === null) {
            return;
        }

        $out = ReservationUnit::query()->whereKey($current->reservation_unit_id)->where('status', UnitStatus::CheckedOut)->exists();

        if ($out && $this->folioBalance->forFolio($current) === '0.00') {
            ($this->closeFolio)($actor, $current);
        }
    }
}
