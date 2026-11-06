<?php

namespace App\Actions\BookingEngine;

use App\Models\SiteTemplate;

/**
 * Make a template's slots match the given list, in that order. Slots are matched by key; a key that is no longer listed is
 * removed. Images already uploaded to a removed slot stay in storage and show again if the key comes back.
 */
class SyncSiteTemplateSlots
{
    /**
     * @param  list<array{key: string, label: string, required: bool}>  $slots
     */
    public function __invoke(SiteTemplate $template, array $slots): void
    {
        $template->slots()->whereNotIn('key', array_column($slots, 'key'))->delete();

        foreach ($slots as $position => $slot) {
            $template->slots()->updateOrCreate(
                ['key' => $slot['key']],
                ['label' => $slot['label'], 'required' => $slot['required'], 'position' => $position + 1],
            );
        }
    }
}
