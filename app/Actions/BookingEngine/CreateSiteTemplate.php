<?php

namespace App\Actions\BookingEngine;

use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Models\Property;
use App\Models\SiteTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Add a template to the registry as Draft. The key must match a template folder in the microsite repo. A Custom template
 * belongs to one property; a Standard one has none. The request checks the key is new and the property fits the type.
 */
class CreateSiteTemplate
{
    public function __construct(private SyncSiteTemplateSlots $syncSlots) {}

    /**
     * @param  list<array{key: string, label: string, required: bool}>  $slots
     */
    public function __invoke(User $admin, string $key, string $name, SiteTemplateType $type, ?Property $property, ?string $previewUrl, array $slots, bool $allowsMainColor = false): SiteTemplate
    {
        return DB::transaction(function () use ($admin, $key, $name, $type, $property, $previewUrl, $slots, $allowsMainColor): SiteTemplate {
            $template = SiteTemplate::query()->create([
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'property_id' => $type === SiteTemplateType::Custom ? $property?->id : null,
                'status' => SiteTemplateStatus::Draft,
                'allows_main_color' => $allowsMainColor,
                'preview_url' => $previewUrl,
            ]);

            ($this->syncSlots)($template, $slots);

            activity()
                ->performedOn($template)
                ->causedBy($admin)
                ->event('site-template.created')
                ->withProperties(['key' => $key, 'type' => $type->value, 'property_id' => $template->property_id])
                ->log('Booking website template added');

            return $template;
        });
    }
}
