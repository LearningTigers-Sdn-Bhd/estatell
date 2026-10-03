<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReopenFolio
{
    /**
     * Reopen a Closed folio. A Voided folio cannot reopen. The invoice number stays on the folio, so closing it again
     * issues no new number. The folio row is locked first and a refusal writes and logs nothing. Permissions are
     * checked by the route. See docs/13-folio/01-folios-and-lines.md.
     */
    public function __invoke(User $actor, Folio $folio): FolioStatusResult
    {
        return DB::transaction(function () use ($actor, $folio): FolioStatusResult {
            $current = Folio::query()->lockForUpdate()->findOrFail($folio->id);

            if (! $current->status->canTransitionTo(FolioStatus::Open)) {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_NOT_CLOSED);
            }

            $current->forceFill([
                'status' => FolioStatus::Open,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            activity()
                ->performedOn($current->reservation)
                ->causedBy($actor)
                ->event('folio.reopened')
                ->withProperties([
                    'property_id' => $current->property_id,
                    'folio_id' => $current->id,
                ])
                ->log('Folio reopened');

            return FolioStatusResult::done($current);
        });
    }
}
