<?php

namespace App\Actions\Reservations;

use App\Actions\CityLedger\RecordCreditOverride;
use App\Actions\Folio\SyncRoutingRules;
use App\Actions\Webhooks\TriggerWebhook;
use App\Data\Reservations\NewReservationData;
use App\Data\Reservations\NewUnitData;
use App\Enums\CompanyStatus;
use App\Enums\DocumentType;
use App\Enums\RemarkKind;
use App\Enums\ReservationStatus;
use App\Enums\SellUnit;
use App\Enums\StayType;
use App\Enums\WebhookEvent;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\RoomType;
use App\Models\User;
use App\Services\CityLedger\CreditCheck;
use App\Services\NightAudit\NextDocumentNumber;
use App\Services\Reservations\MarketSegments;
use App\Services\Reservations\ReservationShortCode;
use App\Services\Reservations\SalesPeople;
use App\Services\Reservations\StayChargeTotal;
use Illuminate\Support\Facades\DB;

class CreateReservation
{
    public function __construct(
        private AddUnit $addUnit,
        private NextDocumentNumber $nextDocumentNumber,
        private ReservationShortCode $reservationShortCode,
        private SyncRoutingRules $syncRoutingRules,
        private CreditCheck $creditCheck,
        private RecordCreditOverride $recordCreditOverride,
        private StayChargeTotal $stayChargeTotal,
        private SetReservationPaymentSchedule $setPaymentSchedule,
        private SalesPeople $salesPeople,
        private MarketSegments $marketSegments,
    ) {}

    /**
     * Create a reservation and its units in one transaction.
     *
     * A Bed room type line adds one unit for each bed. A reservation is all overnight or all day use (`stay_types_mixed`), and day use never comes from a channel. When any line is refused, nothing is saved, the reference
     * number is not used up, nothing is logged, and the result names the line. `$fromChannel` and `$allowPastArrival` are passed to `AddUnit`. A sales person must be an active staff user of the property (`sales_person_not_available`), and a market segment an active segment of the property (`market_segment_not_available`). Each remark of `$data->remarks` becomes one `reservation_remarks` row (docs/12-reservations/16-remarks.md). A company that pays per booking gives the reservation its payment deadlines (`SetReservationPaymentSchedule`, not for channel bookings). `SyncRoutingRules` runs when the units are in, so a company or a group reservation gets its Master folio and rules. See docs/12-reservations/02-group-bookings.md.
     */
    public function __invoke(?User $actor, Property $property, NewReservationData $data, bool $fromChannel = false, bool $allowPastArrival = false): ReservationResult
    {
        if ($data->units === []) {
            return ReservationResult::refused(ReservationResult::REFUSAL_UNITS_REQUIRED);
        }

        if (! Guest::query()->where('property_id', $property->id)->notMerged()->whereNull('anonymized_at')->whereKey($data->bookerGuestId)->exists()) {
            return ReservationResult::refused(ReservationResult::REFUSAL_BOOKER_NOT_FOUND);
        }

        if ($data->companyId !== null && ! Company::query()->where('property_id', $property->id)->where('status', CompanyStatus::Active)->whereKey($data->companyId)->exists()) {
            return ReservationResult::refused(ReservationResult::REFUSAL_COMPANY_NOT_AVAILABLE);
        }

        if ($data->bookingSource->property_id !== $property->id) {
            return ReservationResult::refused(ReservationResult::REFUSAL_BOOKING_SOURCE_NOT_AVAILABLE);
        }

        if ($data->salesPersonId !== null && ! $this->salesPeople->isAvailable($property, $data->salesPersonId)) {
            return ReservationResult::refused(ReservationResult::REFUSAL_SALES_PERSON_NOT_AVAILABLE);
        }

        if ($data->marketSegmentId !== null && ! $this->marketSegments->isAvailable($property, $data->marketSegmentId)) {
            return ReservationResult::refused(ReservationResult::REFUSAL_MARKET_SEGMENT_NOT_AVAILABLE);
        }

        $stayTypes = array_unique(array_map(fn (NewUnitData $line): string => $line->stayType->value, $data->units));

        if (count($stayTypes) > 1) {
            return ReservationResult::refused(ReservationResult::REFUSAL_STAY_TYPES_MIXED);
        }

        if ($fromChannel && $stayTypes === [StayType::DayUse->value]) {
            return ReservationResult::refused(ReservationResult::REFUSAL_DAY_USE_NOT_FOR_CHANNEL);
        }

        try {
            return DB::transaction(function () use ($actor, $property, $data, $fromChannel, $allowPastArrival): ReservationResult {
                $roomTypes = RoomType::query()
                    ->where('property_id', $property->id)
                    ->whereIn('id', array_unique(array_map(fn (NewUnitData $line): int => $line->roomTypeId, $data->units)))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $reservation = new Reservation([
                    'booker_guest_id' => $data->bookerGuestId,
                    'company_id' => $data->companyId,
                    'group_name' => $data->groupName,
                    'billing_mode' => $data->billingMode,
                    'collected_taxes_payer' => $data->companyId === null ? null : $data->collectedTaxesPayer,
                    'external_reference' => $data->externalReference,
                    'notes' => $data->notes === null || trim($data->notes) === '' ? null : trim($data->notes),
                ]);
                $reservation->forceFill([
                    'property_id' => $property->id,
                    'booking_source_id' => $data->bookingSource->id,
                    'sales_person_id' => $data->salesPersonId,
                    'market_segment_id' => $data->marketSegmentId,
                    'reference' => ($this->nextDocumentNumber)($property, DocumentType::Reservation)->reference,
                    'short_code' => $this->reservationShortCode->generate($property),
                    'status' => ReservationStatus::Confirmed,
                    'created_by' => $actor?->id,
                ])->save();

                foreach ($data->remarks as $kind => $body) {
                    $remark = new ReservationRemark(['kind' => RemarkKind::from($kind), 'body' => $body]);
                    $remark->forceFill(['property_id' => $property->id, 'reservation_id' => $reservation->id])->save();
                }

                $roomCount = count(array_filter($data->units, fn (NewUnitData $line): bool => $line->stayType === StayType::Overnight && $roomTypes->get($line->roomTypeId)?->sell_unit === SellUnit::Room));

                foreach ($data->units as $index => $line) {
                    foreach (range(1, $this->unitCount($roomTypes->get($line->roomTypeId), $line, $index)) as $ignored) {
                        $result = ($this->addUnit)($actor, $reservation, $line, $index, $fromChannel, $allowPastArrival, $roomCount);

                        if ($result->isRefused()) {
                            throw new ReservationRefused($result);
                        }
                    }
                }

                $this->checkCredit($actor, $reservation, $data);

                if (! $fromChannel) {
                    ($this->setPaymentSchedule)($reservation);
                }

                ($this->syncRoutingRules)($reservation);

                activity()
                    ->performedOn($reservation)
                    ->causedBy($actor)
                    ->event('reservation.created')
                    ->withProperties([
                        'property_id' => $property->id,
                        'reference' => $reservation->reference,
                        'booking_source_id' => $data->bookingSource->id,
                        'booking_source' => $data->bookingSource->name,
                        'sales_person_id' => $data->salesPersonId,
                        'market_segment_id' => $data->marketSegmentId,
                        'remarks' => array_keys($data->remarks),
                        'units' => $reservation->units()->count(),
                        'has_notes' => $reservation->notes !== null,
                    ])
                    ->log('Reservation created');

                app(TriggerWebhook::class)(WebhookEvent::BookingCreated, $property->id, Reservation::class, $reservation->id);

                return ReservationResult::created($reservation);
            });
        } catch (ReservationRefused $refused) {
            return $refused->result;
        }
    }

    /**
     * Refuse a company that the stay charges with tax (`StayChargeTotal`) would take over its credit limit, unless `creditOverrideReason` is given
     * (the route passes it only to users with `company.credit-override`), which is then logged. A reservation without
     * a company is never checked. See docs/15-city-ledger/04-credit-control-and-reports.md.
     *
     * @throws ReservationRefused when the company would go over its limit and there is no override reason
     */
    private function checkCredit(?User $actor, Reservation $reservation, NewReservationData $data): void
    {
        $company = $data->companyId === null ? null : Company::query()->find($data->companyId);

        if ($company === null) {
            return;
        }

        $check = $this->creditCheck->check($company, $this->stayChargeTotal->forReservation($reservation));

        if ($check->allowed) {
            return;
        }

        if ($actor === null || trim((string) $data->creditOverrideReason) === '') {
            throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_CREDIT_LIMIT_EXCEEDED));
        }

        ($this->recordCreditOverride)($actor, $company, $check, (string) $data->creditOverrideReason, 'create_reservation');
    }

    /**
     * How many units a line adds: one for each bed of a Bed room type, otherwise one. An unknown room type adds one
     * and `AddUnit` refuses it.
     *
     * @throws ReservationRefused when the bed count does not fit the room type
     */
    private function unitCount(?RoomType $roomType, NewUnitData $line, int $index): int
    {
        if ($roomType === null) {
            return 1;
        }

        if ($line->stayType === StayType::DayUse) {
            return 1;
        }

        if ($roomType->sell_unit === SellUnit::Bed) {
            return $line->beds < 1 ? throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_INVALID_STAY, $index)) : $line->beds;
        }

        return $line->beds > 1 ? throw new ReservationRefused(ReservationResult::refused(ReservationResult::REFUSAL_INVALID_STAY, $index)) : 1;
    }
}
