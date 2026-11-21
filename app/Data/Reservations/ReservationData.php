<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\RemarkKind;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use Spatie\LaravelData\Data;

/**
 * The header and summary card of the reservation page. Expects `booker`, `creator`, `salesPerson`, `marketSegment`, `remarks` and `company` to be loaded. The figures come
 * from the units that hold inventory, and the dates fall back to all units when none does.
 */
class ReservationData extends Data
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $shortCode,
        public string $title,
        public ?string $groupName,
        public bool $isGroup,
        public bool $isClosed,
        public BadgeData $status,
        public ?string $firstArrival,
        public ?string $lastDeparture,
        public int $unitCount,
        public int $roomNights,
        public StayType $stayType,
        public ?string $stayLabel,
        public string $stayTotal,
        public ?string $balance,
        public string $billingMode,
        public string $billingModeLabel,
        public string $sourceName,
        public BookingSourceBadgeData $source,
        public ?string $externalReference,
        public ?string $notes,
        public ?int $companyId,
        public ?string $companyName,
        public string $createdOn,
        public ?string $createdBy,
        public ?int $salesPersonId,
        public ?string $salesPersonName,
        public ?int $marketSegmentId,
        public ?string $marketSegmentName,
        /** @var list<ReservationRemarkData> */
        public array $remarks,
        public ReservationBookerData $booker,
        public ?CollectedTaxesData $collectedTaxes = null,
    ) {}

    public static function fromModel(
        Reservation $reservation,
        int $unitCount,
        ?string $firstArrival,
        ?string $lastDeparture,
        int $roomNights,
        StayType $stayType,
        ?string $stayLabel,
        string $stayTotal,
        ?string $balance,
        bool $canViewGuest,
        ?CollectedTaxesData $collectedTaxes = null,
    ): self {
        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            shortCode: $reservation->short_code,
            title: $reservation->group_name ?? $reservation->booker->full_name,
            groupName: $reservation->group_name,
            isGroup: $unitCount > 1,
            isClosed: in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::Completed], true),
            status: BadgeData::fromReservationStatus($reservation->status),
            firstArrival: $firstArrival,
            lastDeparture: $lastDeparture,
            unitCount: $unitCount,
            roomNights: $roomNights,
            stayType: $stayType,
            stayLabel: $stayLabel,
            stayTotal: $stayTotal,
            balance: $balance,
            billingMode: $reservation->billing_mode->value,
            billingModeLabel: $reservation->billing_mode->label(),
            sourceName: $reservation->bookingSource->name,
            source: BookingSourceBadgeData::fromModel($reservation->bookingSource),
            externalReference: $reservation->external_reference,
            notes: $reservation->notes,
            companyId: $reservation->company_id,
            companyName: $reservation->company?->name,
            createdOn: $reservation->created_at->toDateString(),
            createdBy: $reservation->creator?->name,
            salesPersonId: $reservation->sales_person_id,
            salesPersonName: $reservation->salesPerson?->name,
            marketSegmentId: $reservation->market_segment_id,
            marketSegmentName: $reservation->marketSegment?->name,
            remarks: self::remarksInOrder($reservation),
            booker: ReservationBookerData::fromModel($reservation->booker, $canViewGuest),
            collectedTaxes: $collectedTaxes,
        );
    }

    /**
     * The remarks of the booking in `RemarkKind` order.
     *
     * @return list<ReservationRemarkData>
     */
    private static function remarksInOrder(Reservation $reservation): array
    {
        $byKind = $reservation->remarks->keyBy(fn (ReservationRemark $remark): string => $remark->kind->value);
        $remarks = [];

        foreach (RemarkKind::cases() as $kind) {
            $remark = $byKind->get($kind->value);

            if ($remark !== null) {
                $remarks[] = ReservationRemarkData::fromModel($remark);
            }
        }

        return $remarks;
    }
}
