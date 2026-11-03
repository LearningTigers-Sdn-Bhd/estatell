<?php

namespace App\Actions\Reservations;

use App\Actions\Payments\RecordPayment;
use App\Data\Boats\BoatTripData;
use App\Data\Payments\NewPaymentData;
use App\Data\Reservations\ArrivalImportRowData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Data\Reservations\UnitPickData;
use App\Enums\ArrivalImportOutcome;
use App\Enums\BillingMode;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Features\Boats;
use App\Models\BookingSource;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Import the planned rows of an arrival file, all or nothing. See docs/12-reservations/13-arrival-import.md.
 */
class ImportArrivals
{
    public function __construct(
        private CreateBooking $createBooking,
        private CreatePastStay $createPastStay,
        private RecordPayment $recordPayment,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * Rows with the same group number and outcome become one reservation: `CreateBooking` (no auto-assign) for a
     * booking or hold, `CreatePastStay` for a past stay. Each unit is priced at the row's total on its room type's
     * enter-price plan; the reason overrides restrictions. A deposit becomes an Old system Advance on the Master folio
     * when there is one, else on the row's unit. Any refusal rolls everything back and names the file line. Rows with a
     * problem refuse the whole import; Skipped and Already imported rows are left out. Logs `reservation.arrivals_imported`
     * on the property.
     *
     * @param  list<ArrivalImportRowData>  $rows  From `ArrivalImportPlanner`.
     */
    public function __invoke(User $actor, Property $property, array $rows, string $reason, string $fileName, bool $canOverrideCredit = false, bool $withoutCard = false): ArrivalImportResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            return ArrivalImportResult::refused(BookingResult::REFUSAL_REASON_REQUIRED);
        }

        $withProblem = array_find($rows, fn (ArrivalImportRowData $row): bool => $row->problems !== []);

        if ($withProblem !== null) {
            return ArrivalImportResult::refused(ArrivalImportResult::REFUSAL_HAS_PROBLEMS, $withProblem->line);
        }

        $groups = [];

        foreach ($rows as $row) {
            if ($row->imports()) {
                $groups[$row->groupNo.'|'.$row->outcome->value][] = $row;
            }
        }

        if ($groups === []) {
            return ArrivalImportResult::refused(ArrivalImportResult::REFUSAL_NOTHING_TO_IMPORT);
        }

        $depositMethod = PaymentMethod::query()->where('property_id', $property->id)->where('type', PaymentMethodType::OldSystem)->first();

        if ($depositMethod === null && array_any($rows, fn (ArrivalImportRowData $row): bool => $row->imports() && $row->deposit !== null)) {
            return ArrivalImportResult::refused(ArrivalImportResult::REFUSAL_NO_OLD_SYSTEM_METHOD);
        }

        $hasBoats = Feature::for($property)->active(Boats::class);
        $businessDate = $this->currentBusinessDate->date($property);

        try {
            return DB::transaction(function () use ($actor, $property, $groups, $reason, $fileName, $canOverrideCredit, $withoutCard, $depositMethod, $hasBoats, $businessDate): ArrivalImportResult {
                $units = 0;
                $deposits = 0;

                foreach ($groups as $groupRows) {
                    $reservation = $this->createGroup($actor, $property, $groupRows, $reason, $canOverrideCredit, $withoutCard, $hasBoats);
                    $units += count($groupRows);
                    $deposits += $this->recordDeposits($actor, $property, $reservation, $groupRows, $depositMethod, $businessDate);
                }

                activity()
                    ->performedOn($property)
                    ->causedBy($actor)
                    ->event('reservation.arrivals_imported')
                    ->withProperties(['property_id' => $property->id, 'file' => $fileName, 'reason' => $reason, 'reservations' => count($groups), 'units' => $units, 'deposits' => $deposits])
                    ->log('Arrivals imported');

                return ArrivalImportResult::done(count($groups), $units, $deposits);
            });
        } catch (ArrivalImportRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * @param  list<ArrivalImportRowData>  $rows
     *
     * @throws ArrivalImportRefused
     */
    private function createGroup(User $actor, Property $property, array $rows, string $reason, bool $canOverrideCredit, bool $withoutCard, bool $hasBoats): Reservation
    {
        $first = $rows[0];
        $isPast = $first->outcome->isPastStay();

        $notes = array_map(fn (ArrivalImportRowData $row): string => trim(__('Old system :number', ['number' => $row->resNo])
            .($row->outcome === ArrivalImportOutcome::Hold ? ' · '.__('Hold in old system') : '')
            .($row->notes === null ? '' : ' · '.$row->notes)), $rows);

        $data = new NewBookingData(
            bookingSource: BookingSource::query()->where('property_id', $property->id)->findOrFail($first->bookingSourceId),
            units: array_map(fn (ArrivalImportRowData $row): NewUnitData => $this->unit($property, $row, $reason, $hasBoats && ! $isPast), $rows),
            guests: $this->guests($property, $rows),
            picks: array_values(array_filter(array_map(
                fn (ArrivalImportRowData $row, int $index): ?UnitPickData => $row->roomId === null ? null : new UnitPickData(unitIndex: $index, roomId: $row->roomId),
                $rows, array_keys($rows),
            ))),
            billingMode: BillingMode::MasterPays,
            externalReference: $first->groupNo,
            groupName: count($rows) > 1 ? ($first->company ?? $first->guest) : null,
            notes: implode(PHP_EOL, $notes),
            companyId: $first->companyId,
            creditOverrideReason: $canOverrideCredit ? $reason : null,
        );

        $result = $isPast
            ? ($this->createPastStay)($actor, $property, $data, $reason, $withoutCard, $first->outcome === ArrivalImportOutcome::PastCheckedOut)
            : ($this->createBooking)($actor, $property, $data, autoAssignRooms: false);

        if ($result->isRefused()) {
            $row = $rows[$result->lineIndex ?? $result->unitIndex ?? 0] ?? $first;

            throw new ArrivalImportRefused(ArrivalImportResult::refused((string) $result->refusal, $row->line, $result->date));
        }

        return $result->reservation;
    }

    private function unit(Property $property, ArrivalImportRowData $row, string $reason, bool $withBoats): NewUnitData
    {
        return new NewUnitData(
            roomTypeId: (int) $row->roomTypeId,
            ratePlanId: (int) $row->ratePlanId,
            arrivalDate: CarbonImmutable::parse((string) $row->arrival),
            departureDate: CarbonImmutable::parse((string) $row->departure),
            adults: $row->adults,
            childAges: array_fill(0, $row->children, 6),
            overrideTotal: $row->total,
            overrideReason: __('Old system price (:number)', ['number' => $row->resNo]),
            restrictionOverrideReason: $reason,
            arrivalTime: $row->arrivalTime,
            departureTime: $row->departureTime,
            boatTrips: $withBoats ? $this->boatTrips($property, $row) : [],
        );
    }

    /**
     * @return list<BoatTripData>
     */
    private function boatTrips(Property $property, ArrivalImportRowData $row): array
    {
        $trips = [];

        foreach ([[BoatDirection::In, $row->boatIn, $row->boatInDepartureId, $row->arrivalTime ?? $property->settings->check_in_time ?? '15:00'],
            [BoatDirection::Out, $row->boatOut, $row->boatOutDepartureId, $row->departureTime ?? $property->settings->check_out_time ?? '11:00']] as [$direction, $given, $departureId, $unitTime]) {
            if ($departureId !== null) {
                $trips[] = new BoatTripData(direction: $direction, mode: BoatMode::Scheduled, boatDepartureId: $departureId);
            } elseif ($given !== null && str_contains($given, 'OWN')) {
                $trips[] = new BoatTripData(direction: $direction, mode: BoatMode::Own, time: substr((string) $unitTime, 0, 5));
            }
        }

        return $trips;
    }

    /**
     * One guest per row, on its unit; the first is the booker. A guest of the property with the same name (ignoring
     * case) is reused, so a repeat name has one profile.
     *
     * @param  list<ArrivalImportRowData>  $rows
     * @return list<NewBookingGuestData>
     */
    private function guests(Property $property, array $rows): array
    {
        return array_map(function (ArrivalImportRowData $row, int $index) use ($property): NewBookingGuestData {
            $guestId = Guest::query()->where('property_id', $property->id)->whereRaw('lower(full_name) = ?', [mb_strtolower($row->guest)])->value('id');

            return $guestId === null
                ? new NewBookingGuestData(profile: ['full_name' => $row->guest], unitIndex: $index)
                : new NewBookingGuestData(guestId: $guestId, unitIndex: $index);
        }, $rows, array_keys($rows));
    }

    /**
     * @param  list<ArrivalImportRowData>  $rows
     *
     * @throws ArrivalImportRefused
     */
    private function recordDeposits(User $actor, Property $property, Reservation $reservation, array $rows, ?PaymentMethod $method, ?CarbonImmutable $businessDate): int
    {
        $units = $reservation->units()->orderBy('id')->get()->values();
        $master = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Master)->orderBy('id')->first();
        $count = 0;

        foreach ($rows as $index => $row) {
            if ($row->deposit === null || $method === null) {
                continue;
            }

            $key = "arrival-import:{$property->id}:{$row->resNo}";
            $result = ($this->recordPayment)($actor, $reservation, $master, $master === null ? $units->get($index) : null, new NewPaymentData(
                paymentMethodId: $method->id,
                purpose: PaymentPurpose::Advance,
                amount: $row->deposit,
                reference: $row->resNo,
                idempotencyKey: $key,
                serviceDate: $businessDate?->toDateString(),
                migrated: true,
                lineKey: $key,
            ));

            if ($result->isRefused()) {
                throw new ArrivalImportRefused(ArrivalImportResult::refused((string) $result->refusal, $row->line));
            }

            $count++;
        }

        return $count;
    }
}
