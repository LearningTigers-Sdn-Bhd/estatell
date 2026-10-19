<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\PostingResult;
use App\Actions\Folio\PostOpeningBalance;
use App\Actions\Folio\PrepareOldFolios;
use App\Actions\FrontDesk\CheckInUnit;
use App\Data\Reservations\NewBookingData;
use App\Enums\OldFolioMode;
use App\Enums\StayType;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateMigratedStay
{
    public function __construct(
        private CreateBooking $createBooking,
        private CheckInUnit $checkInUnit,
        private PostOpeningBalance $postOpeningBalance,
        private PrepareOldFolios $prepareOldFolios,
        private MigrationWindow $migrationWindow,
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
    ) {}

    /**
     * Bring in a guest who is in the house now and arrived before the go-live date, in one transaction:
     * `CreateBooking` with `allowPastArrival`, `CheckInUnit` for each unit with `asOf` set to the arrival date and time
     * (its `PostMissedNights` posts the nights from go-live to yesterday, and never a night before go-live), then, for
     * One amount (an empty amount is zero), `PostOpeningBalance` on the folio of the first unit with the old folio number. Copy line by line
     * saves the stay with no opening line and gives each unit its own folio (`PrepareOldFolios`), so the old folio page has a tab for it. The nights before go-live keep their quoted amount and are never charged.
     * Only inside the migration window (`migration_window_closed`). The arrival must be before the go-live date
     * (`arrival_not_before_go_live`) and the departure after the business date (`departure_not_after_today`). Every
     * unit needs a room or bed pick, and a reason is required. A refusal rolls everything back and names the unit or
     * line. Logs `reservation.migrated_stay_created` with the reason and the units. `$withoutCard` is passed as in
     * `CreatePastStay`. Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#migrated-stay.
     */
    public function __invoke(User $actor, Property $property, NewBookingData $data, string $reason, OldFolioMode $oldFolio, ?string $openingAmount, ?string $legacyNumber, bool $withoutCard = false): BookingResult
    {
        if (trim($reason) === '') {
            return BookingResult::refused(BookingResult::REFUSAL_REASON_REQUIRED);
        }

        if (! $this->migrationWindow->isOpen($property)) {
            return BookingResult::refused(BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED);
        }

        $businessDate = $this->currentBusinessDate->date($property);
        $goLive = $this->firstBusinessDate->date($property);
        $line = $data->units[0] ?? null;

        if ($businessDate === null || $goLive === null) {
            return BookingResult::refused('no_business_date');
        }

        if ($line === null || array_any($data->units, fn ($unit): bool => $unit->stayType !== StayType::Overnight)) {
            return BookingResult::refused(BookingResult::REFUSAL_PAST_STAY_NIGHTS_ONLY, lineIndex: 0);
        }

        $arrival = $line->arrivalDate->startOfDay();

        if ($arrival->gte($goLive)) {
            return BookingResult::refused(BookingResult::REFUSAL_ARRIVAL_NOT_BEFORE_GO_LIVE, lineIndex: 0);
        }

        if ($line->departureDate->startOfDay()->lte($businessDate)) {
            return BookingResult::refused(BookingResult::REFUSAL_DEPARTURE_NOT_AFTER_TODAY, lineIndex: 0);
        }

        $openingAmount = trim((string) $openingAmount) === '' ? '0' : (string) $openingAmount;

        if ($oldFolio === OldFolioMode::OneAmount && PostOpeningBalance::normalize($openingAmount) === null) {
            return BookingResult::refused(PostingResult::REFUSAL_OPENING_AMOUNT_INVALID);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $reason, $withoutCard, $arrival, $line, $oldFolio, $openingAmount, $legacyNumber, $businessDate, $goLive): BookingResult {
                $result = ($this->createBooking)($actor, $property, $data, allowPastArrival: true);

                if ($result->isRefused()) {
                    throw new BookingRefused($result);
                }

                $reservation = $result->reservation;
                $checkedInAt = CarbonImmutable::parse($arrival->toDateString().' '.($line->arrivalTime ?? $property->settings->check_in_time ?? '15:00'));
                $units = $reservation->units()->orderBy('id')->get();

                foreach ($units as $index => $unit) {
                    $stay = ($this->checkInUnit)($actor, $unit, $withoutCard, $data->creditOverrideReason, $checkedInAt);

                    if ($stay->isRefused()) {
                        throw new BookingRefused(BookingResult::refused((string) $stay->refusal, unitIndex: $index));
                    }
                }

                if ($oldFolio === OldFolioMode::OneAmount) {
                    $posted = ($this->postOpeningBalance)($actor, $units->firstOrFail(), $openingAmount, $legacyNumber);

                    if ($posted->isRefused()) {
                        throw new BookingRefused(BookingResult::refused((string) $posted->refusal));
                    }
                } else {
                    ($this->prepareOldFolios)($reservation);
                }

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.migrated_stay_created')
                    ->withProperties([
                        'property_id' => $property->id,
                        'reason' => trim($reason),
                        'units' => $units->pluck('id')->all(),
                        'old_folio' => $oldFolio->value,
                        'business_date' => $businessDate->toDateString(),
                        'go_live_date' => $goLive->toDateString(),
                    ])
                    ->log('Migrated stay created');

                return BookingResult::created($reservation);
            });
        } catch (BookingRefused $refused) {
            return $refused->result;
        }
    }
}
