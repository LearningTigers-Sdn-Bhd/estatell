<?php

namespace App\Http\Resources\Booking\V1;

use App\Enums\SystemTransactionCode;
use App\Models\Amenity;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A property's public content. The first photo is the cover. A nearby place never shows its review status.
 *
 * @property Property $resource
 */
class BookingPropertyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource;

        return [
            'code' => $property->code,
            'slug' => $property->slug,
            'name' => $property->name,
            'address' => [
                'line1' => $property->address_line1,
                'line2' => $property->address_line2,
                'city' => $property->city,
                'state_code' => $property->state_code,
                'postal_code' => $property->postal_code,
                'country_code' => $property->country_code,
            ],
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            'google_maps_url' => $property->google_maps_url,
            'contact_phone' => $property->contact_phone,
            'contact_email' => $property->contact_email,
            'photos' => $property->getMedia(Property::PHOTOS)->map(fn (Media $photo): array => [
                'url' => $photo->getUrl('web'),
                'thumb_url' => $photo->getUrl('thumb'),
            ])->values()->all(),
            'about_text' => $property->bookingSite?->about_text,
            'amenities' => $property->amenities->map(fn (Amenity $amenity): array => [
                'name' => $amenity->name,
                'category' => $amenity->category->name,
                'icon' => $amenity->icon,
            ])->values()->all(),
            'nearby_places' => $property->nearbyPlaces->map(fn (NearbyPlace $place): array => [
                'name' => $place->pivot->display_name ?? $place->name,
                'description' => $place->pivot->description,
                'latitude' => $place->latitude,
                'longitude' => $place->longitude,
                'google_maps_url' => $place->google_maps_url,
            ])->values()->all(),
            'check_in_time' => $this->stayTime($property->settings?->check_in_time),
            'check_out_time' => $this->stayTime($property->settings?->check_out_time),
            'currency' => $property->settings?->currency->getCurrencyCode(),
            'policies' => [
                'cancellation' => $this->policy(SystemTransactionCode::CancellationRevenue),
                'no_show' => $this->policy(SystemTransactionCode::NoShowRevenue),
            ],
        ];
    }

    /** A stay time as `15:00`. The column also holds seconds. */
    private function stayTime(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * @return array{text_en: string, text_ms: string}|null
     */
    private function policy(SystemTransactionCode $code): ?array
    {
        /** @var RoomRevenuePolicy|null $policy */
        $policy = $this->resource->roomRevenuePolicies->firstWhere('code', $code);

        return $policy === null ? null : ['text_en' => $policy->text_en, 'text_ms' => $policy->text_ms];
    }
}
