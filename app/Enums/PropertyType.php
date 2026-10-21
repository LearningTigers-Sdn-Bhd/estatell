<?php

namespace App\Enums;

enum PropertyType: string
{
    case Hotel = 'hotel';
    case Hostel = 'hostel';
    case Resort = 'resort';
    case ServicedApartment = 'serviced_apartment';
    case Homestay = 'homestay';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => __('Hotel'),
            self::Hostel => __('Hostel'),
            self::Resort => __('Resort'),
            self::ServicedApartment => __('Serviced apartment'),
            self::Homestay => __('Homestay'),
        };
    }

    /**
     * The Channex property type. It affects Channex billing. The serviced apartment mapping is to verify.
     */
    public function channexType(): string
    {
        return match ($this) {
            self::Hotel => 'hotel',
            self::Hostel => 'hostel',
            self::Resort => 'resort',
            self::ServicedApartment => 'apart_hotel',
            self::Homestay => 'homestay',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
