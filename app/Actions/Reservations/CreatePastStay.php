<?php

namespace App\Actions\Reservations;

use App\Actions\FrontDesk\CheckInUnit;
use App\Data\Reservations\NewBookingData;
use App\Enums\StayType;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreatePastStay
{
    public function __construct(
        private CreateBooking $createBooking,
        private CheckInUnit $checkInUnit,
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
    ) {}

    /**
     * Enter a stay that began before the business date and is still in the house, in one transaction: `CreateBooking`
     * with `allowPastArrival`, then `CheckInUnit` for each unit with `asOf` set to the arrival date and time of the
     * form. The check-in posts every night before the business date through `PostMissedNights`, each with its own
     * service date. The stay must begin before the business date and on or after the go-live date, and end after the
     * business date. Every unit needs a room or bed pick, and a reason is required. `$withoutCard` skips the
     * registration card rule: no card can exist before the stay does, so the route passes it to a user with
     * `front-desk.check-in-without-card`. No policy runs. Any refusal rolls everything back and names the unit or
     * line. Permissions (`folio.manual`) are checked by the route.
     * See docs/12-reservations/12-past-and-migrated-stays.md#past-stay.
     */
    public function __invoke(User $actor, Property $property, NewBookingData $data, string $reason, bool $withoutCard = false): BookingResult
    {
        if (trim($reason) === '') {
            return BookingResult::refused(BookingResult::REFUSAL_REASON_REQUIRED);
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

        if ($arrival->gte($businessDate)) {
            return BookingResult::refused(BookingResult::REFUSAL_ARRIVAL_NOT_PAST, lineIndex: 0);
        }

        if ($arrival->lt($goLive)) {
            return BookingResult::refused(BookingResult::REFUSAL_ARRIVAL_BEFORE_GO_LIVE, lineIndex: 0);
        }

        if ($line->departureDate->startOfDay()->lte($businessDate)) {
            return BookingResult::refused(BookingResult::REFUSAL_DEPARTURE_NOT_AFTER_TODAY, lineIndex: 0);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $reason, $withoutCard, $arrival, $line, $businessDate): BookingResult {
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

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.past_stay_created')
                    ->withProperties([
                        'property_id' => $property->id,
                        'reason' => trim($reason),
                        'units' => $units->pluck('id')->all(),
                        'business_date' => $businessDate->toDateString(),
                    ])
                    ->log('Past stay created');

                return BookingResult::created($reservation);
            });
        } catch (BookingRefused $refused) {
            return $refused->result;
        }
    }
}
