<?php

namespace App\Data\BookingEngine;

use App\Data\Property\PropertyPhotoData;
use App\Enums\SiteTemplateType;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use Spatie\LaravelData\Data;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What the Design tab shows. The template list holds what the property can pick, plus the template it already uses
 * even if that was retired. The image rows follow the saved template, so they appear after the template is saved.
 */
class BookingWebsiteDesignData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $templates
     * @param  list<SiteImageSlotData>  $slots
     * @param  list<PropertyPhotoData>  $albumPhotos
     */
    public function __construct(
        public ?int $siteTemplateId,
        public bool $allowsMainColor,
        public ?string $mainColor,
        public array $templates,
        public array $slots,
        public int $imageMaxKb,
        public array $albumPhotos,
    ) {}

    public static function fromSite(PropertyBookingSite $site): self
    {
        $templates = SiteTemplate::query()->pickableBy($site->property)
            ->when($site->site_template_id !== null, fn ($query) => $query->orWhereKey($site->site_template_id))
            ->orderBy('name')
            ->get();

        $images = $site->getMedia(PropertyBookingSite::IMAGES)->keyBy(fn (Media $media): string => (string) $media->getCustomProperty('slot'));

        return new self(
            siteTemplateId: $site->site_template_id,
            allowsMainColor: $site->siteTemplate !== null && $site->siteTemplate->allows_main_color,
            mainColor: $site->main_color,
            templates: $templates
                ->map(fn (SiteTemplate $template): array => [
                    'value' => (string) $template->id,
                    'label' => $template->type === SiteTemplateType::Custom ? __(':name (custom design)', ['name' => $template->name]) : $template->name,
                ])
                ->values()
                ->all(),
            slots: $site->siteTemplate === null ? [] : array_values($site->siteTemplate->slots
                ->map(fn (SiteTemplateSlot $slot): SiteImageSlotData => SiteImageSlotData::fromModels($slot, $images->get($slot->key)))
                ->all()),
            imageMaxKb: PropertyBookingSite::IMAGE_MAX_KB,
            albumPhotos: array_values($site->property->getMedia(Property::PHOTOS)
                ->map(fn (Media $media): PropertyPhotoData => PropertyPhotoData::fromModel($media))
                ->all()),
        );
    }
}
