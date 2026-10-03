<?php

namespace App\Actions\Folio;

use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Support\Facades\DB;

class CloseFolio
{
    public function __construct(
        private FolioBalance $folioBalance,
        private NextDocumentNumber $nextDocumentNumber,
    ) {}

    /**
     * Close an Open folio with a balance of 0. The folio row is locked first, and every check runs before the first
     * write, so a refusal writes and logs nothing. Closing changes the status and assigns `invoice_number` once: a
     * folio that was reopened keeps its number. Ledger lines are never touched. Permissions are checked by the route.
     * The refusal of a Master folio while a unit is in-house waits for the front desk states. See
     * docs/13-folio/01-folios-and-lines.md.
     */
    public function __invoke(User $actor, Folio $folio): FolioStatusResult
    {
        return DB::transaction(function () use ($actor, $folio): FolioStatusResult {
            $current = Folio::query()->lockForUpdate()->findOrFail($folio->id);

            if (! $current->status->canTransitionTo(FolioStatus::Closed)) {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_NOT_OPEN);
            }

            if ($this->folioBalance->forFolio($current) !== '0.00') {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_BALANCE_NOT_ZERO);
            }

            $current->forceFill([
                'status' => FolioStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'invoice_number' => $current->invoice_number ?? ($this->nextDocumentNumber)($current->property, DocumentType::Invoice)->reference,
            ])->save();

            activity()
                ->performedOn($current->reservation)
                ->causedBy($actor)
                ->event('folio.closed')
                ->withProperties([
                    'property_id' => $current->property_id,
                    'folio_id' => $current->id,
                    'invoice_number' => $current->invoice_number,
                ])
                ->log('Folio closed');

            return FolioStatusResult::done($current);
        });
    }
}
