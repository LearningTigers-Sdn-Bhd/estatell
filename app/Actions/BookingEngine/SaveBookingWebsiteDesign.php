<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\SiteTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Save the Design tab: the template and the main colour. Images are kept per slot key, so images in slots the new template
 * also has carry over. The main colour is kept only when the chosen template allows one. Returns false, and writes nothing, when the template is not one this property can pick: it must be
 * Available and Standard or the property's own Custom one. Keeping the template already chosen is always allowed, even if retired.
 */
class SaveBookingWebsiteDesign
{
    public function __invoke(PropertyBookingSite $site, User $actor, ?int $siteTemplateId, ?string $mainColor): bool
    {
        return DB::transaction(function () use ($site, $actor, $siteTemplateId, $mainColor): bool {
            $site = PropertyBookingSite::query()->with('property')->lockForUpdate()->findOrFail($site->id);

            if ($siteTemplateId !== null && $siteTemplateId !== $site->site_template_id) {
                $pickable = SiteTemplate::query()->pickableBy($site->property)->whereKey($siteTemplateId)->exists();

                if (! $pickable) {
                    return false;
                }
            }

            $mainColor = $siteTemplateId !== null && SiteTemplate::query()->whereKey($siteTemplateId)->where('allows_main_color', true)->exists() ? $mainColor : null;

            $before = ['site_template_id' => $site->site_template_id, 'main_color' => $site->main_color];
            $site->update(['site_template_id' => $siteTemplateId, 'main_color' => $mainColor]);

            if (! $site->wasChanged(['site_template_id', 'main_color'])) {
                return true;
            }

            activity()
                ->performedOn($site)
                ->causedBy($actor)
                ->event($site->wasChanged('site_template_id') ? 'booking-website.template-changed' : 'booking-website.design-saved')
                ->withProperties(['property_id' => $site->property_id, 'from' => $before, 'to' => ['site_template_id' => $siteTemplateId, 'main_color' => $mainColor]])
                ->log($site->wasChanged('site_template_id') ? 'Booking website template changed' : 'Booking website design saved');

            return true;
        });
    }
}
