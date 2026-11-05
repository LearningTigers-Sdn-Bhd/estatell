<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\Property;
use App\Models\PropertyBookingSite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What the microsite needs to pick a template and fill it. Images of slots the template no longer has are left out.
 *
 * @property Property $resource
 */
class BookingSiteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PropertyBookingSite $site The reach check has found a site for this property. */
        $site = $this->resource->bookingSite;
        $slots = $site->siteTemplate?->slots->pluck('key')->all() ?? [];
        $images = $site->getMedia(PropertyBookingSite::IMAGES)
            ->filter(fn (Media $image): bool => in_array($image->getCustomProperty('slot'), $slots, true));

        return [
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'template_key' => $site->siteTemplate?->key,
            'images' => $images->map(fn (Media $image): array => [
                'slot' => $image->getCustomProperty('slot'),
                'url' => $image->getUrl('web'),
                'thumb_url' => $image->getUrl('thumb'),
            ])->values()->all(),
            'main_color' => $site->main_color,
            'currency' => $this->resource->settings?->currency->getCurrencyCode(),
            'seo_title' => $site->seo_title,
            'seo_description' => $site->seo_description,
        ];
    }
}
