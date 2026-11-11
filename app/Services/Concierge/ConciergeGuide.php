<?php

namespace App\Services\Concierge;

use App\Data\Concierge\ConciergeFaqData;
use App\Data\Concierge\ConciergePoliciesData;
use App\Data\Concierge\ConciergePropertyData;
use App\Data\Concierge\ConciergeWifiData;
use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\PropertyFaq;
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
        );
    }

    public function policies(Property $property): ConciergePoliciesData
    {
        $property->loadMissing('settings');

        return new ConciergePoliciesData(
            $this->time($property->settings->check_in_time),
            $this->time($property->settings->check_out_time),
            $property->settings->house_rules,
        );
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
        return $property->wifiNetworks()->get()->map(fn (PropertyWifiNetwork $network): ConciergeWifiData => ConciergeWifiData::fromModel($network))->all();
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
