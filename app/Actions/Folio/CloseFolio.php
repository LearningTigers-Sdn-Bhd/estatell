<?php

namespace App\Actions\Folio;

use App\Enums\DocumentType;
use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\IncomingScheduledCharges;
use App\Services\Folio\InHouseUnits;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Support\Facades\DB;

class CloseFolio
{
    public function __construct(
        private FolioBalance $folioBalance,
        private InHouseUnits $inHouseUnits,
        private IncomingScheduledCharges $incomingScheduledCharges,
        private NextDocumentNumber $nextDocumentNumber,
    ) {}

    /**
     * Close an Open folio with a balance of 0. A Master folio is refused while a unit is CheckedIn (`units_in_house`,
     * naming the rooms), before the balance check. Any folio is refused while a live unit still routes unposted
     * Scheduled rows to it (`folio_receives_scheduled_charges`, naming the rooms and dates), or the next night audit
     * would fail those nights. The folio row is locked first, and every check runs before the first
     * write, so a refusal writes and logs nothing. Closing changes the status and assigns `invoice_number` once: a
     * folio that was reopened keeps its number. Ledger lines are never touched. Permissions are checked by the route.
     * See docs/13-folio/01-folios-and-lines.md.
     */
    public function __invoke(User $actor, Folio $folio): FolioStatusResult
    {
        return DB::transaction(function () use ($actor, $folio): FolioStatusResult {
            $current = Folio::query()->lockForUpdate()->findOrFail($folio->id);

            if (! $current->status->canTransitionTo(FolioStatus::Closed)) {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_NOT_OPEN);
            }

            $rooms = $this->inHouseUnits->roomsFor($current);

            if ($rooms !== []) {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_UNITS_IN_HOUSE, $rooms);
            }

            $incoming = $this->incomingScheduledCharges->for($current);

            if ($incoming !== []) {
                return FolioStatusResult::refused(FolioStatusResult::REFUSAL_RECEIVES_SCHEDULED_CHARGES, $incoming);
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
