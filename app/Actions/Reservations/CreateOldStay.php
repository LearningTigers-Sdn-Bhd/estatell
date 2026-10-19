<?php

namespace App\Actions\Reservations;

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\PrepareOldFolios;
use App\Actions\FrontDesk\CheckInUnit;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Data\Reservations\NewBookingData;
use App\Enums\StayType;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Property\MigrationWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateOldStay
{
    public function __construct(
        private CreateBooking $createBooking,
        private CheckInUnit $checkInUnit,
        private CheckOutUnit $checkOutUnit,
        private PrepareOldFolios $prepareOldFolios,
        private CloseFolio $closeFolio,
        private MigrationWindow $migrationWindow,
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
    ) {}

    /**
     * Enter a stay that began and ended before the go-live date, for room history, the police report and the guest's
     * stay history. It holds no money, in one transaction: `CreateBooking` with `allowPastArrival`, `CheckInUnit` with
     * `asOf` set to the arrival, `CheckOutUnit` with `asOf` set to the departure and `skipBalanceCheck`, then
     * `PrepareOldFolios` (nothing creates a folio before the first posting) and `CloseFolio` on every folio of the reservation without an invoice number. Nothing posts: a night before go-live is
     * never charged. The room is not marked Dirty. Only inside the migration window (`migration_window_closed`). The
     * arrival and the departure must be before the go-live date (`arrival_not_before_go_live`,
     * `departure_not_before_go_live`). Every unit needs a room or bed pick, a night that is not free is refused, and a
     * reason is required. A refusal rolls everything back and names the unit or line. Logs
     * `reservation.old_stay_created` with the reason and the units. `$withoutCard` is passed as in `CreatePastStay`.
     * Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#old-stay.
     */
    public function __invoke(User $actor, Property $property, NewBookingData $data, string $reason, bool $withoutCard = false): BookingResult
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
        $departure = $line->departureDate->startOfDay();

        if ($arrival->gte($goLive)) {
            return BookingResult::refused(BookingResult::REFUSAL_ARRIVAL_NOT_BEFORE_GO_LIVE, lineIndex: 0);
        }

        if ($departure->gte($goLive)) {
            return BookingResult::refused(BookingResult::REFUSAL_DEPARTURE_NOT_BEFORE_GO_LIVE, lineIndex: 0);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $reason, $withoutCard, $arrival, $departure, $line, $businessDate, $goLive): BookingResult {
                $result = ($this->createBooking)($actor, $property, $data, allowPastArrival: true);

                if ($result->isRefused()) {
                    throw new BookingRefused($result);
                }

                $reservation = $result->reservation;
                $checkedInAt = CarbonImmutable::parse($arrival->toDateString().' '.($line->arrivalTime ?? $property->settings->check_in_time ?? '15:00'));
                $checkedOutAt = CarbonImmutable::parse($departure->toDateString().' '.($line->departureTime ?? $property->settings->check_out_time ?? '11:00'));
                $units = $reservation->units()->orderBy('id')->get();

                foreach ($units as $index => $unit) {
                    $stay = ($this->checkInUnit)($actor, $unit, $withoutCard, $data->creditOverrideReason, $checkedInAt);

                    if ($stay->isRefused()) {
                        throw new BookingRefused(BookingResult::refused((string) $stay->refusal, unitIndex: $index));
                    }
                }

                foreach ($units as $index => $unit) {
                    $stay = ($this->checkOutUnit)($actor, $unit, $checkedOutAt, skipBalanceCheck: true);

                    if ($stay->isRefused()) {
                        throw new BookingRefused(BookingResult::refused((string) $stay->refusal, unitIndex: $index));
                    }
                }

                ($this->prepareOldFolios)($reservation);

                foreach ($reservation->folios()->orderBy('id')->get() as $folio) {
                    if (($this->closeFolio)($actor, $folio, issueInvoiceNumber: false)->isRefused()) {
                        throw new BookingRefused(BookingResult::refused('old_stay_folio_not_closed'));
                    }
                }

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.old_stay_created')
                    ->withProperties([
                        'property_id' => $property->id,
                        'reason' => trim($reason),
                        'units' => $units->pluck('id')->all(),
                        'business_date' => $businessDate->toDateString(),
                        'go_live_date' => $goLive->toDateString(),
                    ])
                    ->log('Old stay created');

                return BookingResult::created($reservation);
            });
        } catch (BookingRefused $refused) {
            return $refused->result;
        }
    }
}
