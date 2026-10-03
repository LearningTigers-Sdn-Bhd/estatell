<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One card or row of the reservations list. Expects the list query of `ReservationDirectory`: the `booker` and
 * `units.roomType` relations and the aggregate columns (`first_arrival`, `last_departure`, `live_units`, `all_units`,
 * `adults`, `children`, `stay_total`, `paid`, `balance`). Dates, counts and the total come from units that hold
 * inventory. `paid` and `balance` come from the folios that are not Voided, and are 0.00 while the reservation has none.
 */
class ReservationListItemData extends Data
{
    /**
     * @param  list<string>  $roomTypes  Room types as "N× name".
     */
    public function __construct(
        public int $id,
        public string $reference,
        public string $title,
        public string $bookerName,
        public ?int $bookerId,
        public bool $isGroup,
        public bool $isVip,
        public bool $isBlacklisted,
        public bool $isRepeat,
        public bool $isCancelled,
        public BadgeData $status,
        public string $source,
        public string $sourceLabel,
        public ?string $channelName,
        public ?string $externalReference,
        public int $unitCount,
        public int $adults,
        public int $children,
        public ?string $firstArrival,
        public ?string $lastDeparture,
        public int $nights,
        public array $roomTypes,
        public string $bookedOn,
        public string $total,
        public string $paid,
        public string $balance,
        public ReservationRoomBoxData $roomBox,
    ) {}

    /**
     * @param  bool  $canViewGuest  Whether the user may open the booker's guest page. Without it the id is left out.
     */
    public static function fromModel(Reservation $reservation, ReservationRoomBoxData $roomBox, bool $isRepeat, bool $canViewGuest): self
    {
        $booker = $reservation->booker;
        $liveUnits = (int) $reservation->getAttribute('live_units');
        $unitCount = $liveUnits > 0 ? $liveUnits : (int) $reservation->getAttribute('all_units');
        $counted = $reservation->units->filter(fn (ReservationUnit $unit): bool => $liveUnits === 0 || $unit->status->holdsInventory());
        $firstArrival = $reservation->getAttribute('first_arrival');
        $lastDeparture = $reservation->getAttribute('last_departure');

        return new self(
            id: $reservation->id,
            reference: $reservation->reference,
            title: $reservation->group_name ?? $booker->full_name,
            bookerName: $booker->full_name,
            bookerId: $canViewGuest ? $booker->id : null,
            isGroup: $unitCount > 1,
            isVip: $booker->is_vip,
            isBlacklisted: $booker->blacklisted_at !== null,
            isRepeat: $isRepeat,
            isCancelled: $reservation->status === ReservationStatus::Cancelled,
            status: BadgeData::fromReservationStatus($reservation->status),
            source: $reservation->source->value,
            sourceLabel: $reservation->source->label(),
            channelName: $reservation->source === ReservationSource::Channel ? $reservation->channel_name : null,
            externalReference: $reservation->external_reference,
            unitCount: $unitCount,
            adults: (int) $reservation->getAttribute('adults'),
            children: (int) $reservation->getAttribute('children'),
            firstArrival: $firstArrival,
            lastDeparture: $lastDeparture,
            nights: $firstArrival === null ? 0 : (int) CarbonImmutable::parse($firstArrival)->diffInDays(CarbonImmutable::parse($lastDeparture)),
            roomTypes: $counted->countBy(fn (ReservationUnit $unit): string => $unit->roomType->name)
                ->map(fn (int $count, string $name): string => __(':count× :name', ['count' => $count, 'name' => $name]))
                ->values()
                ->all(),
            bookedOn: $reservation->created_at->toDateString(),
            total: BigDecimal::of((string) $reservation->getAttribute('stay_total'))->toScale(2)->__toString(),
            paid: (string) $reservation->getAttribute('paid'),
            balance: (string) $reservation->getAttribute('balance'),
            roomBox: $roomBox,
        );
    }
}
