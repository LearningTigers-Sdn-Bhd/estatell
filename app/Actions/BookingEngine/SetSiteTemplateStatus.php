<?php

namespace App\Actions\BookingEngine;

use App\Enums\SiteTemplateStatus;
use App\Models\SiteTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move a template between Draft, Available and Retired. Returns false, and writes nothing, when the move is not allowed
 * from its current status. Retiring only hides it from new picks; sites already using it keep working.
 */
class SetSiteTemplateStatus
{
    public function __invoke(User $admin, SiteTemplate $template, SiteTemplateStatus $status): bool
    {
        return DB::transaction(function () use ($admin, $template, $status): bool {
            $template = SiteTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if (! $template->status->canTransitionTo($status)) {
                return false;
            }

            $from = $template->status;
            $template->update(['status' => $status]);

            activity()
                ->performedOn($template)
                ->causedBy($admin)
                ->event('site-template.status-changed')
                ->withProperties(['from' => $from->value, 'to' => $status->value])
                ->log('Booking website template status changed');

            return true;
        });
    }
}
