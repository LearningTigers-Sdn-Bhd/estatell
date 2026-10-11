<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioRouter;
use Illuminate\Support\Facades\DB;

class BillToGuest
{
    public function __construct(
        private CreateFolio $createFolio,
        private FolioRouter $folioRouter,
    ) {}

    /**
     * Bill a guest of the unit on a folio of their own. The primary guest becomes the payer of the unit's Unit folio
     * (made when the unit has none; only an Open one can change payer). Any other guest of the unit gets a Third party
     * folio, and an Open one that already has that guest as payer is reused. Charges posted earlier stay where they
     * are. The unit row is locked first, so two clicks never make two folios. A refusal writes and logs nothing.
     * See docs/13-folio/03-routing.md#bill-to-guest.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Guest $guest): BillToGuestResult
    {
        return DB::transaction(function () use ($actor, $unit, $guest): BillToGuestResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $onUnit = $locked->guests()->where('guest_id', $guest->id)->first();

            if ($locked->property_id !== $actor->property_id || $onUnit === null) {
                return BillToGuestResult::refused(BillToGuestResult::REFUSAL_GUEST_NOT_ON_UNIT);
            }

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
                return BillToGuestResult::refused(BillToGuestResult::REFUSAL_UNIT_NOT_BILLABLE);
            }

            return $onUnit->is_primary
                ? $this->unitFolio($actor, $locked, $guest)
                : $this->thirdParty($actor, $locked, $guest);
        });
    }

    private function unitFolio(User $actor, ReservationUnit $unit, Guest $guest): BillToGuestResult
    {
        $folio = $this->folioRouter->unitFolio($unit);
        $created = $folio === null;
        $folio ??= ($this->createFolio)($unit->reservation, $unit);

        if (! $created && $folio->status !== FolioStatus::Open) {
            return BillToGuestResult::refused(BillToGuestResult::REFUSAL_FOLIO_NOT_OPEN);
        }

        if ($folio->payer_guest_id === $guest->id) {
            return BillToGuestResult::refused(BillToGuestResult::REFUSAL_ALREADY_BILLED);
        }

        $before = $folio->payer_guest_id;
        $folio->payer_guest_id = $guest->id;
        $folio->save();

        $this->log($actor, $unit, $folio, 'folio.payer_changed', 'Folio payer changed', ['previous_payer_guest_id' => $before, 'payer_guest_id' => $guest->id]);

        return BillToGuestResult::billed($folio, $created);
    }

    private function thirdParty(User $actor, ReservationUnit $unit, Guest $guest): BillToGuestResult
    {
        $existing = Folio::query()
            ->where('reservation_id', $unit->reservation_id)
            ->where('type', FolioType::ThirdParty)
            ->where('status', FolioStatus::Open)
            ->where('payer_guest_id', $guest->id)
            ->oldest('id')
            ->first();

        if ($existing !== null) {
            return BillToGuestResult::billed($existing, false);
        }

        return BillToGuestResult::billed(($this->createFolio)->thirdParty($unit->reservation, $guest, null, $actor), true);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, ReservationUnit $unit, Folio $folio, string $event, string $message, array $properties): void
    {
        activity()
            ->performedOn($unit->reservation)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'property_id' => $unit->property_id,
                'reservation_unit_id' => $unit->id,
                'folio_id' => $folio->id,
                ...$properties,
            ])
            ->log($message);
    }
}
