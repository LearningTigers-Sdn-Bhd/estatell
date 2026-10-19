<?php

namespace App\Actions\Folio;

use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Property\MigrationWindow;

class AddOldFolio
{
    public const REFUSAL_MASTER_EXISTS = 'master_exists';

    public function __construct(
        private MigrationWindow $migrationWindow,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Add a folio for the old folio page: a Master folio (paid by the reservation's company) or, with a payer, a
     * third-party folio, both through `CreateFolio`. The new folio is empty and Open, so it gets a tab. Only inside the
     * migration window (`migration_window_closed`). A reservation that already has an active Master folio refuses another
     * (`master_exists`). Logs `folio.master_created` for a Master folio (`CreateFolio` logs a third-party one).
     * Returns the folio, or the refusal code. Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#copy-line-by-line.
     */
    public function __invoke(User $actor, Reservation $reservation, ?Guest $payerGuest, ?int $payerCompanyId): Folio|string
    {
        if (! $this->migrationWindow->isOpen($reservation->property)) {
            return PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED;
        }

        if ($payerGuest !== null || $payerCompanyId !== null) {
            return $this->createFolio->thirdParty($reservation, $payerGuest, $payerCompanyId, $actor);
        }

        if ($reservation->folios()->where('type', FolioType::Master)->where('status', '!=', FolioStatus::Voided)->exists()) {
            return self::REFUSAL_MASTER_EXISTS;
        }

        $folio = ($this->createFolio)($reservation, null);

        activity()
            ->performedOn($reservation)
            ->causedBy($actor)
            ->event('folio.master_created')
            ->withProperties(['property_id' => $reservation->property_id, 'folio_id' => $folio->id, 'number' => $folio->number])
            ->log('Master folio created');

        return $folio;
    }
}
