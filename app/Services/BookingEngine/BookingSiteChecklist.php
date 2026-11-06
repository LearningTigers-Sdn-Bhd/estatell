<?php

namespace App\Services\BookingEngine;

use App\Data\BookingEngine\BookingSiteChecklistItemData;
use App\Enums\BookingEngineMode;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\SiteTemplateSlot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The "Before you publish" rows, calculated from data. Each later booking engine item adds its own row here (payment setup). Microsite shows the template row; API only leaves it out.
 */
class BookingSiteChecklist
{
    /**
     * @return list<BookingSiteChecklistItemData>
     */
    public function items(PropertyBookingSite $site): array
    {
        $items = [];

        if ($site->mode === BookingEngineMode::Microsite) {
            $chosen = $site->site_template_id !== null;
            $items[] = new BookingSiteChecklistItemData('template', __('Template chosen'), $chosen, $chosen ? null : __('No template chosen yet'));

            if ($chosen) {
                $missing = $this->missingImageLabels($site);
                $items[] = new BookingSiteChecklistItemData('images', __('Required images added'), $missing === [], $missing === [] ? null : __('Add: :images', ['images' => implode(', ', $missing)]));
            }
        }

        $hasPhotos = $site->property->getMedia(Property::PHOTOS)->isNotEmpty();
        $items[] = new BookingSiteChecklistItemData('property-photos', __('Property photos'), $hasPhotos, $hasPhotos ? null : __('Add a photo on Settings, Details, Photos'));

        $withoutPhotos = RoomType::query()
            ->where('property_id', $site->property_id)
            ->active()
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', RoomType::PHOTOS))
            ->count();
        $items[] = new BookingSiteChecklistItemData('room-photos', __('Room photos'), $withoutPhotos === 0, $withoutPhotos === 0 ? null : trans_choice('{1} :count room type has no photos|[2,*] :count room types have no photos', $withoutPhotos));

        $located = $site->property->latitude !== null && $site->property->longitude !== null;
        $items[] = new BookingSiteChecklistItemData('location', __('Location set'), $located, $located ? null : __('Paste your Google Maps link on the General tab'));

        $hasPlan = RatePlan::query()->where('property_id', $site->property_id)->bookableOnline()->exists();
        $items[] = new BookingSiteChecklistItemData('rates', __('Public rate plan'), $hasPlan, $hasPlan ? null : __('Turn on a rate plan on the Rates tab'));

        return $items;
    }

    /**
     * The labels of the required slots of the chosen template that have no image.
     *
     * @return list<string>
     */
    private function missingImageLabels(PropertyBookingSite $site): array
    {
        $filled = $site->getMedia(PropertyBookingSite::IMAGES)->map(fn (Media $media): string => (string) $media->getCustomProperty('slot'))->all();

        return array_values($site->siteTemplate()->firstOrFail()->slots()->where('required', true)->get()
            ->reject(fn (SiteTemplateSlot $slot): bool => in_array($slot->key, $filled, true))
            ->map(fn (SiteTemplateSlot $slot): string => $slot->label)
            ->all());
    }

    public function isComplete(PropertyBookingSite $site): bool
    {
        return collect($this->items($site))->every(fn (BookingSiteChecklistItemData $item): bool => $item->done);
    }
}
