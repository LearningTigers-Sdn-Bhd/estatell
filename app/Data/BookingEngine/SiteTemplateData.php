<?php

namespace App\Data\BookingEngine;

use App\Data\Shared\BadgeData;
use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use Spatie\LaravelData\Data;

/**
 * One row of the admin template registry. Needs `slots` and `property` loaded, and `sites_count` from withCount.
 */
class SiteTemplateData extends Data
{
    /**
     * @param  list<SiteTemplateSlotData>  $slots
     * @param  list<array{value: string, label: string}>  $transitions  The statuses it can move to, with the button text.
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public SiteTemplateType $type,
        public string $typeLabel,
        public ?int $propertyId,
        public ?string $propertyLabel,
        public BadgeData $status,
        public bool $allowsMainColor,
        public ?string $previewUrl,
        public array $slots,
        public int $sitesCount,
        public array $transitions,
    ) {}

    public static function fromModel(SiteTemplate $template): self
    {
        return new self(
            id: $template->id,
            key: $template->key,
            name: $template->name,
            type: $template->type,
            typeLabel: $template->type->label(),
            propertyId: $template->property_id,
            propertyLabel: $template->property === null ? null : $template->property->code.' · '.$template->property->name,
            status: new BadgeData($template->status->value, $template->status->label(), $template->status->tone()),
            allowsMainColor: $template->allows_main_color,
            previewUrl: $template->preview_url,
            slots: array_values($template->slots->map(fn (SiteTemplateSlot $slot): SiteTemplateSlotData => SiteTemplateSlotData::fromModel($slot))->all()),
            sitesCount: (int) $template->getAttribute('sites_count'),
            transitions: array_values(array_map(
                fn (SiteTemplateStatus $status): array => ['value' => $status->value, 'label' => $status->actionLabel()],
                array_filter(SiteTemplateStatus::cases(), fn (SiteTemplateStatus $status): bool => $template->status->canTransitionTo($status)),
            )),
        );
    }
}
