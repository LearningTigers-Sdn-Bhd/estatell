<?php

namespace App\Data\Navigation;

use App\Data\Shared\BadgeData;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use Spatie\LaravelData\Data;

class CommandResultData extends Data
{
    /** @param list<NavItemData> $actions */
    public function __construct(
        public string $id,
        public string $group,
        public string $label,
        public string $description,
        public string $href,
        public string $icon,
        public ?BadgeData $status = null,
        public array $actions = [],
    ) {}

    public static function fromGuest(Guest $guest, Property $property): self
    {
        return new self(
            id: 'guest-'.$guest->id,
            group: __('Guests'),
            label: $guest->full_name,
            description: $guest->phone ?? $guest->email ?? '',
            href: route('guests.show', ['property' => $property->code, 'guest' => $guest->id], absolute: false),
            icon: 'user',
        );
    }

    public static function fromRoom(Room $room, Property $property): self
    {
        return new self(
            id: 'room-'.$room->id,
            group: __('Rooms'),
            label: __('Room :number', ['number' => $room->number]),
            description: implode(' · ', array_filter([$room->roomType->name, $room->roomGroup?->name])),
            href: route('settings.rooms.index', ['property' => $property->code], absolute: false),
            icon: 'door-open',
            status: $room->status === null ? null : new BadgeData($room->status->status->value, $room->status->status->label(), $room->status->status->tone()),
        );
    }

    public static function fromReservation(Reservation $reservation, Property $property, bool $support = false): self
    {
        $destination = route('reservations.show', ['property' => $property->code, 'reservation' => $reservation->id], absolute: false);

        return new self(
            id: 'reservation-'.$reservation->id,
            group: __('Reservations'),
            label: $reservation->reference,
            description: $support ? $property->code.' · '.$property->name : ($reservation->booker?->full_name ?? ''),
            href: $support ? route('view-as.show', ['property' => $property->code, 'to' => $destination], absolute: false) : $destination,
            icon: 'calendar-check',
            status: $support ? null : BadgeData::fromReservationStatus($reservation->status),
            actions: $support ? [
                new NavItemData(__('View as owner'), route('view-as.show', ['property' => $property->code], absolute: false), 'user'),
                new NavItemData(__('View as owner and open reservation'), route('view-as.show', ['property' => $property->code, 'to' => $destination], absolute: false), 'calendar-check'),
            ] : [],
        );
    }

    public static function fromProperty(Property $property, bool $admin, bool $canViewAs): self
    {
        $actions = [];

        if ($canViewAs) {
            $viewAs = route('view-as.show', ['property' => $property->code], absolute: false);
            $actions[] = new NavItemData(__('View as owner'), $viewAs, 'user');
            $actions[] = new NavItemData(__('View as owner and open…'), $viewAs, 'arrow-right', action: 'destinations');

            foreach (['dashboard' => __('Dashboard'), 'reservations.index' => __('Reservations'), 'settings.profile' => __('Property settings')] as $route => $title) {
                $actions[] = new NavItemData($title, route('view-as.show', ['property' => $property->code, 'to' => route($route, ['property' => $property->code], absolute: false)], absolute: false), 'arrow-right', action: 'destination');
            }
        }

        return new self(
            id: 'property-'.$property->id,
            group: __('Properties'),
            label: $property->code.' · '.$property->name,
            description: $property->city ?? '',
            href: route($admin ? 'admin.properties.show' : 'partner.properties.show', $admin ? ['property' => $property->code] : ['code' => $property->code], absolute: false),
            icon: 'building',
            status: BadgeData::fromStatus($property->status),
            actions: $actions,
        );
    }
}
