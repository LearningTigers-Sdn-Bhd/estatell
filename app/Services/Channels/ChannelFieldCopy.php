<?php

namespace App\Services\Channels;

/**
 * Our label, hint and placeholder for an adapter param. Channex's own title is the fallback, and a param without copy
 * gets no hint or placeholder text.
 */
class ChannelFieldCopy
{
    /**
     * @return array{label: string|null, hint: string|null, placeholder: string|null}
     */
    public function for(string $adapterCode, string $param): array
    {
        return $this->copy($adapterCode)[$param] ?? $this->shared()[$param] ?? ['label' => null, 'hint' => null, 'placeholder' => null];
    }

    /**
     * @return array<string, array{label: string|null, hint: string|null, placeholder: string|null}>
     */
    private function copy(string $adapterCode): array
    {
        return match ($adapterCode) {
            'BookingCom' => [
                'hotel_id' => ['label' => __('Hotel ID'), 'hint' => __('Find it at the top of the Booking.com extranet, next to the property name.'), 'placeholder' => __('e.g. 5868189')],
            ],
            'Agoda' => [
                'hotel_id' => ['label' => __('Hotel ID'), 'hint' => __('Find it in the Agoda YCS, next to the property name.'), 'placeholder' => __('e.g. 1234567')],
                'booking_tax_settings' => ['label' => __('Tax in booking amounts'), 'hint' => __('Decides how the booking total reaches the folio. To verify on staging.'), 'placeholder' => null],
            ],
            'CTrip' => [
                'hotel_code' => ['label' => __('Hotel ID'), 'hint' => __('Find it in Trip.com eBooking, under Connectivity settings.'), 'placeholder' => __('e.g. 28707116')],
            ],
            'Hostelworld' => [
                'hotel_id' => ['label' => __('Hotel ID'), 'hint' => __('Find it in the Hostelworld Hoscars extranet.'), 'placeholder' => __('e.g. 12345')],
                'booking_amount_settings' => ['label' => __('Booking amount'), 'hint' => __('Decides how the booking total reaches the folio. To verify on staging.'), 'placeholder' => null],
                'min_stay_type' => ['label' => __('Minimum stay counts from'), 'hint' => null, 'placeholder' => null],
            ],
            'Expedia' => [
                'hotel_id' => ['label' => __('Hotel ID'), 'hint' => __('Find it in the Expedia Partner Central.'), 'placeholder' => __('e.g. 12345678')],
                'booking_amount_settings' => ['label' => __('Booking amount'), 'hint' => __('Decides how the booking total reaches the folio. To verify on staging.'), 'placeholder' => null],
                'min_stay_type' => ['label' => __('Minimum stay counts from'), 'hint' => null, 'placeholder' => null],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array{label: string|null, hint: string|null, placeholder: string|null}>
     */
    private function shared(): array
    {
        return [
            'send_email_notifications' => ['label' => __('Send booking emails'), 'hint' => __('The OTA emails the property for each booking.'), 'placeholder' => null],
            'email' => ['label' => __('Property email'), 'hint' => null, 'placeholder' => __('e.g. reservations@seripagi.my')],
        ];
    }
}
