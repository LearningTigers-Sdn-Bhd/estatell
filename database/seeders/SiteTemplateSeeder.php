<?php

namespace Database\Seeders;

use App\Actions\BookingEngine\SyncSiteTemplateSlots;
use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Models\SiteTemplate;
use Illuminate\Database\Seeder;

/**
 * The first two templates of the microsite repo, as Draft. An admin marks them Available once their code is deployed.
 * Running it again leaves existing templates alone.
 */
class SiteTemplateSeeder extends Seeder
{
    public function run(SyncSiteTemplateSlots $syncSlots): void
    {
        $slots = [
            ['key' => 'hero', 'label' => 'Hero image', 'required' => true],
            ['key' => 'logo', 'label' => 'Logo', 'required' => false],
            ['key' => 'about', 'label' => 'About image', 'required' => false],
            ['key' => 'icon', 'label' => 'Icon', 'required' => false],
        ];

        foreach (['island-resort' => 'Island resort', 'city-hotel' => 'City hotel'] as $key => $name) {
            if (SiteTemplate::query()->where('key', $key)->exists()) {
                continue;
            }

            $template = SiteTemplate::query()->create([
                'key' => $key,
                'name' => $name,
                'type' => SiteTemplateType::Standard,
                'status' => SiteTemplateStatus::Draft,
                'allows_main_color' => true,
            ]);

            $syncSlots($template, $slots);
        }
    }
}
