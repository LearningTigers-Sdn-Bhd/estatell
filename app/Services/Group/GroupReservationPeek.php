<?php

namespace App\Services\Group;

use App\Data\Group\GroupPeekData;
use App\Data\Group\GroupPeekUnitData;
use App\Data\Property\CurrencyData;
use App\Data\Shared\BadgeData;
use App\Enums\PermissionEnum;
use App\Enums\TransferDirection;
use App\Enums\UnitStatus;
use App\Features\Transfers;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Guests\GuestParty;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Staff\GroupProperties;
use App\Services\Transfers\TransferLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Pennant\Feature;

/**
 * Reads one reservation of a group property for the group side panel. Only a property of the group the user may open
 * with `reservations.view` there answers; anything else is null (a 404). It reads only.
 * See docs/03-staff/05-group-mode.md#reservation-panel.
 */
class GroupReservationPeek
{
    public function __construct(
        private GroupProperties $groupProperties,
        private FolioBalance $folioBalance,
        private ReservationRoomBox $roomBox,
        private GuestParty $guestParty,
        private TransferLabel $transferLabel,
    ) {}

    /**
     * @param  Collection<int, Property>  $properties  The properties the user may open in the group.
     */
    public function for(User $user, Collection $properties, string $propertyCode, int $reservationId): ?GroupPeekData
    {
        $property = $properties->first(fn (Property $candidate): bool => $candidate->code === $propertyCode);

        if ($property === null) {
            return null;
        }

        $permissions = $this->groupProperties->permissionsFor($user, $properties, [PermissionEnum::ReservationsView, PermissionEnum::GuestsView])[$property->id] ?? [];

        if (! in_array(PermissionEnum::ReservationsView->value, $permissions, true)) {
            return null;
        }

        $canViewGuest = in_array(PermissionEnum::GuestsView->value, $permissions, true);
        $transfers = Feature::for($property)->active(Transfers::class);
        $reservation = Reservation::query()
            ->where('property_id', $property->id)
            ->with([
                'booker', 'company', 'bookingSource', 'remarks',
                'units' => fn ($units) => $units->orderBy('arrival_date')->orderBy('id'),
                'units.roomType', 'units.guests.guest',
                ...($transfers ? ['units.transfers.transferRoute'] : []),
            ])
            ->find($reservationId);

        if ($reservation === null) {
            return null;
        }

        $live = $reservation->units->reject(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true));
        $shown = $live->isEmpty() ? $reservation->units : $live;
        $rooms = $this->roomBox->assignmentsForUnits(array_values($shown->modelKeys()));
        $money = $this->folioBalance->figuresForReservations([$reservation->id])[$reservation->id] ?? ['total' => '0.00', 'paid' => '0.00', 'balance' => '0.00'];
        $arrival = $shown->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString());
        $departure = $shown->max(fn (ReservationUnit $unit): string => $unit->departure_date->toDateString());
        $booker = $reservation->booker;

        return new GroupPeekData(
            id: $reservation->id,
            reference: $reservation->reference,
            propertyCode: $property->code,
            propertyName: $property->name,
            status: BadgeData::fromReservationStatus($reservation->status),
            booker: $canViewGuest ? $booker?->full_name : null,
            phone: $canViewGuest ? $booker?->phone : null,
            email: $canViewGuest ? $booker?->email : null,
            nationality: $canViewGuest ? $booker?->nationality_code : null,
            company: $reservation->company?->name,
            source: $reservation->bookingSource?->name,
            groupName: $reservation->group_name,
            arrival: (string) $arrival,
            departure: (string) $departure,
            nights: (int) CarbonImmutable::parse($arrival)->diffInDays(CarbonImmutable::parse($departure)),
            units: array_values($shown->map(fn (ReservationUnit $unit): GroupPeekUnitData => new GroupPeekUnitData(
                id: $unit->id,
                label: isset($rooms[$unit->id]) ? "{$unit->roomType->name} · {$rooms[$unit->id]}" : $unit->roomType->name,
                status: BadgeData::fromUnitStatus($unit->status),
                arrival: $unit->arrival_date->toDateString(),
                departure: $unit->departure_date->toDateString(),
                adults: $unit->adults,
                children: $unit->children,
                party: $canViewGuest ? $this->guestParty->line([$unit], $unit->arrival_date, $booker) : null,
                transferIn: $transfers ? $this->transferLabel->forUnit($unit, TransferDirection::In) : null,
                transferOut: $transfers ? $this->transferLabel->forUnit($unit, TransferDirection::Out) : null,
            ))->all()),
            remarks: $reservation->remarks->mapWithKeys(fn (ReservationRemark $remark): array => [$remark->kind->label() => $remark->body])->all(),
            total: $money['total'],
            paid: $money['paid'],
            balance: $money['balance'],
            currency: CurrencyData::forProperty($property),
            reservationUrl: route('reservations.show', [$property->code, $reservation->id], absolute: false),
            guestUrl: $canViewGuest && $booker !== null ? route('guests.show', [$property->code, $booker->id], absolute: false) : null,
        );
    }
}
