<?php

namespace App\Services\BookingEngine;

use App\Data\BookingEngine\BookingSiteChecklistItemData;
use App\Enums\BookingEngineMode;
use App\Models\PropertyBookingSite;

/**
 * The "Before you publish" rows, calculated from data. Each later booking engine item adds its own row here (hero image,
 * room photos, public rate plan, payment setup). Microsite shows the template row; API only leaves it out.
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
        }

        return $items;
    }

    public function isComplete(PropertyBookingSite $site): bool
    {
        return collect($this->items($site))->every(fn (BookingSiteChecklistItemData $item): bool => $item->done);
    }
}
