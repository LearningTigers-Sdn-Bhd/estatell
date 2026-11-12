<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeContactData;
use App\Data\Concierge\ConciergeFaqData;
use App\Data\Concierge\ConciergePoliciesData;
use App\Data\Concierge\ConciergePolicyTermData;
use App\Data\Concierge\ConciergePropertyData;
use App\Data\Concierge\ConciergeTopicData;
use App\Data\Concierge\ConciergeTransportData;
use App\Data\Concierge\ConciergeWifiData;
use App\Enums\BookingEngineMode;
use App\Enums\Locale;
use App\Enums\ParkingAvailability;
use App\Enums\ParkingPriceUnit;
use App\Enums\PolicyTab;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\PropertyFaq;
use App\Models\PropertySetting;
use App\Models\PropertyTransportDetail;
use App\Models\PropertyWifiNetwork;
use Laravel\Pennant\Feature;

/**
 * The property guide a guest reads on the Concierge page: about the property, its policies, its FAQs and, for a guest who
 * is in the house, its Wi-Fi. It reads only. The Wi-Fi is never read for a lobby visitor: the caller asks for it only
 * after it has checked that the stay is checked in.
 */
class ConciergeGuide
{
    public function property(Property $property): ConciergePropertyData
    {
        $property->loadMissing('settings');

        return new ConciergePropertyData(
            name: $property->name,
            addressLines: array_values(array_filter([
                $property->address_line1,
                $property->address_line2,
                trim(($property->postal_code ?? '').' '.($property->city ?? '')),
            ], fn (?string $line): bool => filled($line))),
            phone: $property->contact_phone,
            email: $property->contact_email,
            mapsUrl: $this->mapsUrl($property),
            checkInTime: $this->time($property->settings->check_in_time),
            checkOutTime: $this->time($property->settings->check_out_time),
            about: $property->settings->about,
            arrivalInstructions: $property->settings->arrival_instructions,
            departureInstructions: $property->settings->departure_instructions,
            transport: $this->transport($property),
            topics: array_values($property->infoTopics()->get()->map(ConciergeTopicData::fromModel(...))->all()),
        );
    }

    /**
     * What a guest reads about the front desk and who to call in an emergency. The duty manager and the out-of-hours
     * message are given only while the desk is closed.
     */
    public function contacts(Property $property): ConciergeContactData
    {
        $settings = $property->settings;
        $open = $this->deskOpen($settings);
        $closed = $open === false;
        $digits = preg_replace('/\D+/', '', (string) $settings?->front_desk_whatsapp);

        return new ConciergeContactData(
            deskOpen: $open,
            deskHours: $settings === null || $settings->front_desk_open_24h || $settings->front_desk_opens_at === null || $settings->front_desk_closes_at === null
                ? null
                : $this->time($settings->front_desk_opens_at).' – '.$this->time($settings->front_desk_closes_at),
            whatsappUrl: $digits === '' ? null : 'https://wa.me/'.$digits,
            whatsappNumber: $settings?->front_desk_whatsapp,
            afterHoursMessage: $closed ? $settings->after_hours_message : null,
            dutyManagerPhone: $closed ? $settings->duty_manager_phone : null,
            emergencyPhone: $settings?->emergency_phone,
            emergencyServicesNumber: $settings?->emergency_services_number,
            emergencyInstructions: $settings?->emergency_instructions,
            deskPhone: $settings?->front_desk_phone,
            deskEmail: $settings?->front_desk_email,
            deskExtension: $settings?->front_desk_extension,
        );
    }

    /**
     * True or false when the property has set its desk hours, null when it has not. A desk that closes after midnight is
     * open from its opening time until the closing time of the next morning.
     */
    private function deskOpen(?PropertySetting $settings): ?bool
    {
        if ($settings === null) {
            return null;
        }

        if ($settings->front_desk_open_24h) {
            return true;
        }

        if ($settings->front_desk_opens_at === null || $settings->front_desk_closes_at === null) {
            return null;
        }

        $now = now($settings->time_zone)->format('H:i');
        $opens = $this->time($settings->front_desk_opens_at);
        $closes = $this->time($settings->front_desk_closes_at);

        return $opens <= $closes ? $now >= $opens && $now < $closes : $now >= $opens || $now < $closes;
    }

    private function transport(Property $property): ?ConciergeTransportData
    {
        $transport = $property->transportDetail;

        if ($transport === null) {
            return null;
        }

        $parking = $transport->parking_availability !== null && $transport->parking_availability !== ParkingAvailability::None;

        $data = new ConciergeTransportData(
            directions: $transport->directions,
            airport: $this->trip($transport->airport_distance_km, $transport->airport_travel_minutes),
            station: $this->trip($transport->station_distance_km, $transport->station_travel_minutes),
            cityCentre: $this->trip($transport->city_centre_distance_km, $transport->city_centre_travel_minutes),
            nearestTransitStop: $transport->nearest_transit_stop,
            airportTransferOffered: $transport->airport_transfer_offered,
            airportTransferPrice: $transport->airport_transfer_offered && $transport->airport_transfer_price !== null
                ? $property->settings->currency.' '.number_format((float) $transport->airport_transfer_price, 2)
                : null,
            airportTransferLead: $transport->airport_transfer_offered && $transport->airport_transfer_lead_hours !== null
                ? __('Book :hours hours ahead', ['hours' => $transport->airport_transfer_lead_hours])
                : null,
            pickupPoint: $transport->pickup_point,
            shuttleSchedule: $transport->shuttle_schedule,
            parkingNotes: $transport->parking_notes,
            parkingAvailability: $transport->parking_availability?->label(),
            parkingType: $parking ? $transport->parking_type?->label() : null,
            parkingPrice: $parking ? $this->parkingPrice($property, $transport) : null,
            parkingSpaces: $parking ? $transport->parking_spaces : null,
            parkingHeightLimit: $parking && $transport->parking_height_limit_m !== null ? __(':metres m', ['metres' => (float) $transport->parking_height_limit_m]) : null,
            parkingEvCharging: $parking && $transport->parking_ev_charging,
            parkingBookingRequired: $parking && $transport->parking_booking_required,
            notes: $transport->transport_notes,
        );

        return array_filter($data->toArray(), fn (mixed $value): bool => $value !== null && $value !== false && $value !== '') === [] ? null : $data;
    }

    private function parkingPrice(Property $property, PropertyTransportDetail $transport): ?string
    {
        if ($transport->parking_price_unit === ParkingPriceUnit::Free) {
            return $transport->parking_price_unit->label();
        }

        if ($transport->parking_price === null) {
            return null;
        }

        return $property->settings->currency.' '.number_format((float) $transport->parking_price, 2).($transport->parking_price_unit === null ? '' : ' '.mb_strtolower($transport->parking_price_unit->label()));
    }

    private function trip(?int $km, ?int $minutes): ?string
    {
        $parts = array_filter([$km === null ? null : __(':km km', ['km' => $km]), $minutes === null ? null : __(':minutes min', ['minutes' => $minutes])]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public function policies(Property $property): ConciergePoliciesData
    {
        $property->loadMissing('settings');
        [$smoking, $pets] = $this->roomFacts($property);

        return new ConciergePoliciesData(
            $this->time($property->settings->check_in_time),
            $this->time($property->settings->check_out_time),
            $property->settings->house_rules,
            $property->settings->room_policy,
            $property->settings->payment_policy,
            $property->settings->other_policies,
            $smoking,
            $pets,
            $this->terms($property),
        );
    }

    /**
     * What the room types say about smoking and pets, worded for a guest. Null for both when the property has no active
     * room type, so the page states nothing it does not know.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function roomFacts(Property $property): array
    {
        $types = $property->roomTypes()->active()->get(['smoking_allowed', 'pets_allowed']);

        if ($types->isEmpty()) {
            return [null, null];
        }

        return [
            $types->contains('smoking_allowed', true) ? __('Allowed in some rooms') : __('Not allowed in rooms'),
            $types->contains('pets_allowed', true) ? __('Allowed in some rooms') : __('Not allowed'),
        ];
    }

    /**
     * The owner's wording for each charge policy, in Malay when the property default is Malay and that text is set.
     *
     * @return list<ConciergePolicyTermData>
     */
    public function terms(Property $property): array
    {
        $policies = $property->roomRevenuePolicies()->get()->keyBy(fn ($policy): string => $policy->code->value);
        $malay = $property->settings?->default_locale === Locale::Malay;
        $terms = [];

        foreach ([PolicyTab::Cancellation, PolicyTab::NoShow, PolicyTab::LateCheckout, PolicyTab::EarlyDeparture] as $tab) {
            $policy = $policies->get($tab->code()->value);
            $text = $policy === null ? '' : trim($malay && filled(trim($policy->text_ms)) ? $policy->text_ms : $policy->text_en);

            if ($text !== '') {
                $terms[] = new ConciergePolicyTermData($tab->label(), $text);
            }
        }

        return $terms;
    }

    /**
     * @return array<int, ConciergeFaqData>
     */
    public function faqs(Property $property): array
    {
        return $property->faqs()->get()->map(fn (PropertyFaq $faq): ConciergeFaqData => ConciergeFaqData::fromModel($faq))->all();
    }

    /**
     * @return array<int, ConciergeWifiData>
     */
    public function wifi(Property $property): array
    {
        return $property->wifiNetworks()->where('active', true)->reorder()->orderByDesc('primary_network')->orderBy('position')->orderBy('id')->get()->map(fn (PropertyWifiNetwork $network): ConciergeWifiData => ConciergeWifiData::fromModel($network))->all();
    }

    /**
     * The booking website address, when the property runs a published microsite. Null otherwise, so the page shows no
     * Book a room link that would lead nowhere.
     */
    public function bookingUrl(Property $property): ?string
    {
        $site = $property->bookingSite()->first();

        if (blank($property->slug) || ! Feature::for($property)->active(BookingEngine::class) || $site === null || $site->published_at === null || $site->mode !== BookingEngineMode::Microsite) {
            return null;
        }

        return rtrim((string) config('estatell.booking_site_url'), '/').'/'.$property->slug;
    }

    private function mapsUrl(Property $property): ?string
    {
        if (filled($property->google_maps_url)) {
            return $property->google_maps_url;
        }

        return filled($property->latitude) && filled($property->longitude)
            ? 'https://www.google.com/maps?q='.$property->latitude.','.$property->longitude
            : null;
    }

    /**
     * `15:00:00` shown as `15:00`.
     */
    private function time(string $value): string
    {
        return substr($value, 0, 5);
    }
}
